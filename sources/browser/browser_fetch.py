#!/usr/bin/env python3
"""
SimpleSpyLogger - fetch a URL through a real Chrome over the DevTools protocol.

Some sources (Reddit) block non-browser clients by TLS/HTTP2 fingerprint and
rotate session cookies, so replaying a copied cookie through an impersonating
HTTP client keeps breaking. This drives an actual Chrome instead: a dedicated
profile, started headless with its remote-debugging port on loopback, loads the
URL in a new tab using its own live cookies, and the response body is read back
from the browser's network layer. Nothing is faked and no cookie is copied.

Standard library only, so it runs on any python3 >= 3.6 with no pip installs.
Linux/macOS only (uses fcntl for the profile lock).

Usage:
  browser_fetch.py [options] <url>
      Fetch a URL and print one JSON object (see below).
  browser_fetch.py [options] --login [url]
      Open a normal visible window on the profile so you can log into sites
      once. Close the window when done; the login stays in the profile.

Options:
  --profile DIR   Chrome user-data-dir (default: ./profile next to this script).
                  Use an absolute path; a relative one is taken from the cwd.
  --port N        remote-debugging port, bound to 127.0.0.1 (default 9223)
  --binary PATH   Chrome/Chromium executable (default: first one found on PATH)
  --timeout SEC   overall fetch timeout in seconds (default 45)
  --visible       run the fetch with a window instead of headless (debugging)

If a Chrome is already listening on the port it is reused and left running.
Otherwise one is launched for this fetch and closed cleanly afterwards; the
clean close is what makes Chrome flush rotated cookies back to the profile.
Concurrent fetches on the same profile are serialised by a lock file
(<profile>.lock) so one run never closes a browser another run is using.

Prints one JSON object to stdout, same contract as sources/reddit/fetch.py:
  {"code": <http status>, "body": "<response body>"}  on any HTTP response
  {"error": "<message>"}                               on any other failure
"""

import argparse
import base64
import fcntl
import json
import os
import shutil
import socket
import struct
import subprocess
import sys
import time
import urllib.request
from typing import Any, Dict, List, Optional, Tuple
from urllib.parse import urlparse

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
DEFAULT_PROFILE = os.path.join(SCRIPT_DIR, "profile")
DEFAULT_PORT = 9223
DEFAULT_TIMEOUT = 45.0
BINARY_CANDIDATES = ["google-chrome", "google-chrome-stable", "chromium", "chromium-browser"]


class FetchError(Exception):
    pass


class WebSocket:
    """Minimal RFC 6455 client: enough for the DevTools protocol (text frames,
    client masking, fragmented messages, ping/pong, close)."""

    def __init__(self, url: str, timeout: float) -> None:
        parsed = urlparse(url)
        host = parsed.hostname or "127.0.0.1"
        port = parsed.port or 80
        self.sock = socket.create_connection((host, port), timeout=timeout)
        self.buf = bytearray()

        key = base64.b64encode(os.urandom(16)).decode("ascii")
        path = parsed.path + ("?" + parsed.query if parsed.query else "")
        request = (
            "GET {} HTTP/1.1\r\n"
            "Host: {}:{}\r\n"
            "Upgrade: websocket\r\n"
            "Connection: Upgrade\r\n"
            "Sec-WebSocket-Key: {}\r\n"
            "Sec-WebSocket-Version: 13\r\n\r\n"
        ).format(path, host, port, key)
        self.sock.sendall(request.encode("ascii"))

        while b"\r\n\r\n" not in self.buf:
            chunk = self.sock.recv(4096)
            if not chunk:
                raise FetchError("websocket handshake: connection closed by browser")
            self.buf += chunk
        end = self.buf.index(b"\r\n\r\n")
        status_line = bytes(self.buf[:end]).split(b"\r\n", 1)[0].split()
        del self.buf[:end + 4]
        if len(status_line) < 2 or status_line[1] != b"101":
            raise FetchError("websocket handshake rejected: " + b" ".join(status_line).decode("latin-1"))

    def _read(self, n: int) -> bytes:
        while len(self.buf) < n:
            chunk = self.sock.recv(max(65536, n - len(self.buf)))
            if not chunk:
                raise FetchError("websocket closed by browser")
            self.buf += chunk
        data = bytes(self.buf[:n])
        del self.buf[:n]
        return data

    def _send_frame(self, opcode: int, payload: bytes) -> None:
        header = bytearray([0x80 | opcode])
        length = len(payload)
        if length < 126:
            header.append(0x80 | length)
        elif length < 65536:
            header.append(0x80 | 126)
            header += struct.pack("!H", length)
        else:
            header.append(0x80 | 127)
            header += struct.pack("!Q", length)
        mask = os.urandom(4)
        masked = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))
        self.sock.sendall(bytes(header) + mask + masked)

    def send_text(self, text: str) -> None:
        self._send_frame(0x1, text.encode("utf-8"))

    def recv_text(self) -> str:
        parts = []  # type: List[bytes]
        while True:
            b1, b2 = self._read(2)
            fin = b1 & 0x80
            opcode = b1 & 0x0F
            length = b2 & 0x7F
            if length == 126:
                length = struct.unpack("!H", self._read(2))[0]
            elif length == 127:
                length = struct.unpack("!Q", self._read(8))[0]
            mask = self._read(4) if b2 & 0x80 else None
            payload = self._read(length)
            if mask is not None:
                payload = bytes(b ^ mask[i % 4] for i, b in enumerate(payload))

            if opcode == 0x8:
                raise FetchError("websocket closed by browser")
            if opcode == 0x9:
                self._send_frame(0xA, payload)
                continue
            if opcode == 0xA:
                continue
            # Text (1), binary (2) or a continuation (0) of either.
            parts.append(payload)
            if fin:
                return b"".join(parts).decode("utf-8")

    def close(self) -> None:
        try:
            self.sock.close()
        except OSError:
            pass


class Cdp:
    """DevTools protocol client over one browser-level websocket. Page commands
    are routed to a tab with a flattened sessionId. Events that arrive while
    waiting for a command reply are queued for next_event()."""

    def __init__(self, ws_url: str, deadline: float) -> None:
        self.deadline = deadline
        self.ws = WebSocket(ws_url, self._remaining())
        self.next_id = 0
        self.events = []  # type: List[Dict[str, Any]]

    def _remaining(self) -> float:
        left = self.deadline - time.monotonic()
        if left <= 0:
            raise FetchError("timed out waiting for the browser")
        return left

    def _recv(self) -> Dict[str, Any]:
        self.ws.sock.settimeout(self._remaining())
        try:
            return json.loads(self.ws.recv_text())
        except socket.timeout:
            raise FetchError("timed out waiting for the browser")

    def send(self, method: str, params: Optional[Dict[str, Any]] = None, session_id: Optional[str] = None) -> Dict[str, Any]:
        self.next_id += 1
        msg_id = self.next_id
        msg = {"id": msg_id, "method": method, "params": params or {}}  # type: Dict[str, Any]
        if session_id:
            msg["sessionId"] = session_id
        self.ws.send_text(json.dumps(msg))
        while True:
            reply = self._recv()
            if reply.get("id") == msg_id:
                if "error" in reply:
                    raise FetchError("{}: {}".format(method, reply["error"].get("message", "unknown error")))
                return reply.get("result", {})
            if "method" in reply:
                self.events.append(reply)

    def next_event(self) -> Dict[str, Any]:
        if self.events:
            return self.events.pop(0)
        while True:
            msg = self._recv()
            if "method" in msg:
                return msg

    def close(self) -> None:
        self.ws.close()


def find_binary(explicit: Optional[str]) -> str:
    if explicit:
        return explicit
    for name in BINARY_CANDIDATES:
        path = shutil.which(name)
        if path:
            return path
    raise FetchError("no Chrome/Chromium found on PATH (pass --binary)")


def chrome_command(binary: str, profile: str, port: Optional[int], headless: bool, start_url: str) -> List[str]:
    # --password-store=basic encrypts the profile's cookies with Chrome's fixed
    # fallback key instead of the desktop keyring, so a headless run from cron
    # (no D-Bus session, keyring possibly locked) can still read the logins.
    # The --login window must use the same flag or the cookies it saves would be
    # unreadable to the headless fetches.
    cmd = [
        binary,
        "--user-data-dir=" + profile,
        "--password-store=basic",
        "--no-first-run",
        "--no-default-browser-check",
    ]
    if port is not None:
        cmd.append("--remote-debugging-port={}".format(port))
    if headless:
        cmd.append("--headless=new")
    cmd.append(start_url)
    return cmd


def devtools_ws_url(port: int) -> Optional[str]:
    """Browser-level websocket URL if a Chrome is listening on the port."""
    try:
        with urllib.request.urlopen("http://127.0.0.1:{}/json/version".format(port), timeout=2) as resp:
            return json.loads(resp.read().decode("utf-8")).get("webSocketDebuggerUrl")
    except (OSError, ValueError):
        return None


def launch_chrome(binary: str, profile: str, port: int, headless: bool, deadline: float) -> Tuple[subprocess.Popen, str]:
    os.makedirs(profile, exist_ok=True)
    proc = subprocess.Popen(
        chrome_command(binary, profile, port, headless, "about:blank"),
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
    )
    while time.monotonic() < deadline:
        if proc.poll() is not None:
            raise FetchError(
                "Chrome exited during startup (code {}); is another Chrome already using "
                "this profile without the debugging port, e.g. a --login window?".format(proc.returncode)
            )
        ws_url = devtools_ws_url(port)
        if ws_url:
            return proc, ws_url
        time.sleep(0.25)
    stop_process(proc)
    raise FetchError("Chrome did not open the debugging port {} in time".format(port))


def stop_process(proc: subprocess.Popen) -> None:
    try:
        proc.wait(timeout=10)
        return
    except subprocess.TimeoutExpired:
        proc.terminate()
    try:
        proc.wait(timeout=5)
    except subprocess.TimeoutExpired:
        proc.kill()
        proc.wait()


def acquire_lock(path: str, timeout: float):
    lock_file = open(path, "w")
    deadline = time.monotonic() + timeout
    while True:
        try:
            fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
            return lock_file
        except OSError:
            if time.monotonic() >= deadline:
                lock_file.close()
                raise FetchError("timed out waiting for another fetch to release the profile")
            time.sleep(0.5)


def fetch_in_tab(cdp: Cdp, url: str) -> Dict[str, Any]:
    target_id = cdp.send("Target.createTarget", {"url": "about:blank"})["targetId"]
    try:
        session_id = cdp.send("Target.attachToTarget", {"targetId": target_id, "flatten": True})["sessionId"]

        # Headless Chrome announces itself as "HeadlessChrome" in the
        # User-Agent, which sites block on sight; send the normal Chrome UA.
        user_agent = cdp.send("Browser.getVersion").get("userAgent", "")
        if "HeadlessChrome" in user_agent:
            cdp.send("Network.setUserAgentOverride", {"userAgent": user_agent.replace("HeadlessChrome", "Chrome")}, session_id)

        cdp.send("Network.enable", {"maxResourceBufferSize": 64 * 1024 * 1024, "maxTotalBufferSize": 128 * 1024 * 1024}, session_id)
        nav = cdp.send("Page.navigate", {"url": url}, session_id)
        loader_id = nav.get("loaderId")

        request_id = None  # type: Optional[str]
        status = None  # type: Optional[int]

        def is_main_document(params: Dict[str, Any]) -> bool:
            return params.get("type") == "Document" and (loader_id is None or params.get("loaderId") == loader_id)

        if nav.get("errorText"):
            # Chrome reports an error page (e.g. net::ERR_HTTP_RESPONSE_CODE_FAILURE
            # for a 4xx/5xx with an empty body) as a navigation error even
            # though a real HTTP response came back. Prefer that status if the
            # response event already arrived; otherwise it was a network failure.
            for ev in cdp.events:
                params = ev.get("params", {})
                if ev.get("method") == "Network.responseReceived" and ev.get("sessionId") == session_id and is_main_document(params):
                    return {"code": int(params["response"]["status"]), "body": ""}
            raise FetchError("navigate: " + nav["errorText"])

        while True:
            ev = cdp.next_event()
            if ev.get("sessionId") != session_id:
                continue
            method = ev.get("method")
            params = ev.get("params", {})
            if method == "Network.requestWillBeSent" and is_main_document(params):
                request_id = params.get("requestId")
            elif method == "Network.responseReceived" and is_main_document(params):
                request_id = params.get("requestId")
                status = int(params["response"]["status"])
            elif method == "Network.loadingFinished" and request_id is not None and params.get("requestId") == request_id:
                break
            elif method == "Network.loadingFailed" and request_id is not None and params.get("requestId") == request_id:
                if status is not None:
                    return {"code": status, "body": ""}
                raise FetchError("load failed: " + str(params.get("errorText", "unknown error")))

        if status is None:
            raise FetchError("no response received for the page")
        body = cdp.send("Network.getResponseBody", {"requestId": request_id}, session_id)
        text = body.get("body", "")
        if body.get("base64Encoded"):
            text = base64.b64decode(text).decode("utf-8", "replace")
        return {"code": status, "body": text}
    finally:
        try:
            cdp.send("Target.closeTarget", {"targetId": target_id})
        except (FetchError, OSError):
            pass


def run_fetch(url: str, binary: str, profile: str, port: int, timeout: float, headless: bool) -> Dict[str, Any]:
    lock_file = acquire_lock(profile.rstrip("/") + ".lock", timeout)
    try:
        deadline = time.monotonic() + timeout
        proc = None  # type: Optional[subprocess.Popen]
        ws_url = devtools_ws_url(port)
        if ws_url is None:
            proc, ws_url = launch_chrome(binary, profile, port, headless, deadline)

        cdp = None  # type: Optional[Cdp]
        try:
            cdp = Cdp(ws_url, deadline)
            return fetch_in_tab(cdp, url)
        finally:
            if proc is not None:
                # Only close a browser this run started. Browser.close gets its
                # own short budget so a fetch that hit the overall timeout still
                # shuts Chrome down cleanly (and flushes cookies).
                if cdp is not None:
                    cdp.deadline = time.monotonic() + 5
                    try:
                        cdp.send("Browser.close")
                    except (FetchError, OSError):
                        pass
                stop_process(proc)
            if cdp is not None:
                cdp.close()
    finally:
        lock_file.close()


def main() -> int:
    parser = argparse.ArgumentParser(description="Fetch a URL through a real Chrome over the DevTools protocol.")
    parser.add_argument("url", nargs="?", help="URL to fetch (or to open with --login)")
    parser.add_argument("--profile", default=DEFAULT_PROFILE, help="Chrome user-data-dir")
    parser.add_argument("--port", type=int, default=DEFAULT_PORT, help="remote-debugging port on 127.0.0.1")
    parser.add_argument("--binary", default=None, help="Chrome/Chromium executable")
    parser.add_argument("--timeout", type=float, default=DEFAULT_TIMEOUT, help="overall fetch timeout in seconds")
    parser.add_argument("--visible", action="store_true", help="fetch with a window instead of headless")
    parser.add_argument("--login", action="store_true", help="open a visible window on the profile to log into sites")
    args = parser.parse_args()

    profile = os.path.abspath(args.profile)
    try:
        binary = find_binary(args.binary)
        if args.login:
            os.makedirs(profile, exist_ok=True)
            sys.stderr.write("Opening Chrome on profile {}. Log in, then close the window.\n".format(profile))
            return subprocess.call(chrome_command(binary, profile, None, False, args.url or "about:blank"))
        if not args.url:
            result = {"error": "usage: browser_fetch.py [options] <url>"}  # type: Dict[str, Any]
        else:
            result = run_fetch(args.url, binary, profile, args.port, args.timeout, not args.visible)
    except FetchError as e:
        result = {"error": str(e)}
    except OSError as e:
        result = {"error": "{}: {}".format(type(e).__name__, e)}

    print(json.dumps(result))
    return 0 if "code" in result else 1


if __name__ == "__main__":
    sys.exit(main())
