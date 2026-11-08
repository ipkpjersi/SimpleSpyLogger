#!/usr/bin/env python3
"""
SimpleSpyLogger - Discord gateway probe (measurement only, logs nothing to SSL).

Answers one question before a headless Discord collector is built: if the web
client sits idle on discord.com/app with nobody clicking, which servers still
push full MESSAGE_CREATE events, and which only send PASSIVE_UPDATE_V2 (channel
last_message_id bumps with no content) until the client subscribes to them?

How it works: a dedicated Chrome profile (logged into Discord once with
--login) is started headless with its remote-debugging port on loopback. The
probe opens discord.com/app and reads the gateway websocket frames from Chrome's
network layer (Network.webSocketFrameReceived/Sent). Nothing is injected into
the page and nothing is ever sent on the gateway; the real client does all the
talking. Frames are decompressed (zlib-stream, or zstd-stream when the optional
"zstandard" package is installed) and decoded (JSON or ETF), then only counted.

By default message content is never printed or written. The report holds event
counts, server ids/names, payload key names, and the gateway URL's
compress/encoding parameters. With --log-messages, every MESSAGE_CREATE/UPDATE/
DELETE(_BULK) is also appended as one JSON line (time, server, channel, ids,
author, content, type, flags, attachment/embed counts, and for updates the keys
present) so real samples can be inspected. The client's IDENTIFY/RESUME frames
(which carry the account token) are counted by opcode only and their contents
are never decoded or stored, with or without the flag.

Standard library only, apart from the optional zstandard package. Reuses the
CDP client and launch helpers from sources/browser/browser_fetch.py.

Usage:
  gateway_probe.py --login
      Open a normal visible window on the profile to log into Discord once.
      Close the window when done; the login stays in the profile.
  gateway_probe.py [options]
      Run headless for --duration seconds, then print a per-server summary and
      write the full report to --report.

Options:
  --profile DIR      Chrome user-data-dir (default: ./profile next to this script)
  --port N           remote-debugging port on 127.0.0.1 (default 9224)
  --binary PATH      Chrome/Chromium executable (default: first one found on PATH)
  --duration SEC     how long to watch (default 14400 = 4 hours)
  --report PATH      JSON report path (default: ./probe_report.json next to this script)
  --report-every SEC rewrite the report this often while running (default 300)
  --visible          run with a window instead of headless (debugging)
  --log-messages [PATH]
                     also append message events with content to a JSONL file
                     (default: ./probe_messages.jsonl next to this script)

Ctrl+C (or SIGTERM) stops early and still writes the report.
"""

import argparse
import base64
import json
import os
import select
import signal
import struct
import subprocess
import sys
import time
import zlib
from collections import Counter
from typing import Any, Dict, List, Optional, Set, Tuple
from urllib.parse import parse_qs, urlparse

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(SCRIPT_DIR, "..", "browser"))

from browser_fetch import (  # noqa: E402
    Cdp,
    FetchError,
    acquire_lock,
    chrome_command,
    devtools_ws_url,
    find_binary,
    stop_process,
)

try:
    import zstandard  # type: ignore
except ImportError:
    zstandard = None

DEFAULT_PROFILE = os.path.join(SCRIPT_DIR, "profile")
DEFAULT_REPORT = os.path.join(SCRIPT_DIR, "probe_report.json")
DEFAULT_MESSAGE_LOG = os.path.join(SCRIPT_DIR, "probe_messages.jsonl")
DEFAULT_PORT = 9224
DEFAULT_DURATION = 4 * 3600
DISCORD_APP_URL = "https://discord.com/app"
DISCORD_LOGIN_URL = "https://discord.com/login"
ZLIB_SUFFIX = b"\x00\x00\xff\xff"

# Client->server opcodes whose payload carries the account token. Never decoded.
SECRET_SEND_OPS = {2, 6}

# Keep the client running normally while headless with no visible window.
EXTRA_CHROME_FLAGS = [
    "--disable-background-timer-throttling",
    "--disable-renderer-backgrounding",
    "--disable-backgrounding-occluded-windows",
]


class EtfError(Exception):
    pass


def etf_decode(data: bytes) -> Any:
    """Decode Erlang External Term Format, the subset Discord's gateway uses."""
    if not data or data[0] != 131:
        raise EtfError("missing ETF version byte")
    if len(data) > 1 and data[1] == 80:
        size: int = struct.unpack("!I", data[2:6])[0]
        body: bytes = zlib.decompress(data[6:])
        if len(body) != size:
            raise EtfError("compressed ETF size mismatch")
        value, _ = _etf_term(body, 0)
        return value
    value, _ = _etf_term(data, 1)
    return value


def _etf_atom(name: str) -> Any:
    if name == "nil":
        return None
    if name == "true":
        return True
    if name == "false":
        return False
    return name


def _etf_term(b: bytes, i: int) -> Tuple[Any, int]:
    tag: int = b[i]
    i += 1
    if tag == 97:  # SMALL_INTEGER
        return b[i], i + 1
    if tag == 98:  # INTEGER
        return struct.unpack("!i", b[i:i + 4])[0], i + 4
    if tag == 70:  # NEW_FLOAT
        return struct.unpack("!d", b[i:i + 8])[0], i + 8
    if tag == 99:  # FLOAT (old, 31-byte string)
        return float(b[i:i + 31].split(b"\x00", 1)[0]), i + 31
    if tag in (100, 118):  # ATOM, ATOM_UTF8
        n: int = struct.unpack("!H", b[i:i + 2])[0]
        return _etf_atom(b[i + 2:i + 2 + n].decode("utf-8", "replace")), i + 2 + n
    if tag in (115, 119):  # SMALL_ATOM, SMALL_ATOM_UTF8
        n = b[i]
        return _etf_atom(b[i + 1:i + 1 + n].decode("utf-8", "replace")), i + 1 + n
    if tag in (104, 105):  # SMALL_TUPLE, LARGE_TUPLE
        if tag == 104:
            arity: int = b[i]
            i += 1
        else:
            arity = struct.unpack("!I", b[i:i + 4])[0]
            i += 4
        items: List[Any] = []
        for _ in range(arity):
            v, i = _etf_term(b, i)
            items.append(v)
        return items, i
    if tag == 106:  # NIL (empty list)
        return [], i
    if tag == 107:  # STRING (list of bytes)
        n = struct.unpack("!H", b[i:i + 2])[0]
        return b[i + 2:i + 2 + n].decode("latin-1"), i + 2 + n
    if tag == 108:  # LIST
        n = struct.unpack("!I", b[i:i + 4])[0]
        i += 4
        items = []
        for _ in range(n):
            v, i = _etf_term(b, i)
            items.append(v)
        _, i = _etf_term(b, i)  # tail, NIL for a proper list
        return items, i
    if tag == 109:  # BINARY
        n = struct.unpack("!I", b[i:i + 4])[0]
        return b[i + 4:i + 4 + n].decode("utf-8", "replace"), i + 4 + n
    if tag in (110, 111):  # SMALL_BIG, LARGE_BIG
        if tag == 110:
            n = b[i]
            i += 1
        else:
            n = struct.unpack("!I", b[i:i + 4])[0]
            i += 4
        sign: int = b[i]
        value: int = int.from_bytes(b[i + 1:i + 1 + n], "little")
        return (-value if sign else value), i + 1 + n
    if tag == 116:  # MAP
        n = struct.unpack("!I", b[i:i + 4])[0]
        i += 4
        out: Dict[Any, Any] = {}
        for _ in range(n):
            k, i = _etf_term(b, i)
            v, i = _etf_term(b, i)
            out[k if isinstance(k, (str, int)) else str(k)] = v
        return out, i
    if tag == 77:  # BIT_BINARY
        n = struct.unpack("!I", b[i:i + 4])[0]
        return b[i + 5:i + 5 + n].hex(), i + 5 + n
    raise EtfError("unsupported ETF tag {}".format(tag))


class GatewayStream:
    """Per-websocket decoder state. zlib-stream and zstd-stream are one
    compression context for the whole connection, so a decode failure poisons
    every later frame of that socket."""

    def __init__(self, url: str) -> None:
        query: Dict[str, List[str]] = parse_qs(urlparse(url).query)
        self.compress: str = (query.get("compress") or [""])[0]
        self.encoding: str = (query.get("encoding") or ["json"])[0]
        self.version: str = (query.get("v") or [""])[0]
        self.opened_at: float = time.time()
        self.closed_at: Optional[float] = None
        self.broken: Optional[str] = None
        self.zbuf: bytearray = bytearray()
        self.zobj: Any = None
        if self.compress == "zlib-stream":
            self.zobj = zlib.decompressobj()
        elif self.compress == "zstd-stream":
            if zstandard is None:
                self.broken = "zstd-stream needs the zstandard package (pip install zstandard)"
            else:
                self.zobj = zstandard.ZstdDecompressor().decompressobj()
        elif self.compress:
            self.broken = "unknown compress mode " + self.compress

    def describe(self) -> Dict[str, Any]:
        return {
            "compress": self.compress or None,
            "encoding": self.encoding,
            "v": self.version or None,
            "opened_at": self.opened_at,
            "closed_at": self.closed_at,
            "broken": self.broken,
        }

    def decode_received(self, raw: bytes) -> Optional[Any]:
        """Returns the decoded payload, or None when the frame was only part of
        a zlib message. Raises on a decode error (and marks the stream broken)."""
        if self.broken:
            return None
        try:
            if self.compress == "zlib-stream":
                self.zbuf += raw
                if not self.zbuf.endswith(ZLIB_SUFFIX):
                    return None
                data: bytes = self.zobj.decompress(bytes(self.zbuf))
                self.zbuf.clear()
            elif self.compress == "zstd-stream":
                data = self.zobj.decompress(raw)
            else:
                data = raw
            return self._parse(data)
        except Exception as e:
            self.broken = "{}: {}".format(type(e).__name__, e)
            raise

    def _parse(self, data: bytes) -> Any:
        if self.encoding == "etf":
            return etf_decode(data)
        return json.loads(data.decode("utf-8"))


class ProbeCdp(Cdp):
    """The fetcher's CDP client, adapted for a long-running loop: poll() waits
    for the socket to become readable before reading, so an idle timeout can
    never land in the middle of a frame."""

    def cmd(self, method: str, params: Optional[Dict[str, Any]] = None, session_id: Optional[str] = None) -> Dict[str, Any]:
        self.deadline = time.monotonic() + 30
        return self.send(method, params, session_id)

    def poll(self, timeout: float) -> Optional[Dict[str, Any]]:
        if self.events:
            return self.events.pop(0)
        if not self.ws.buf:
            readable, _, _ = select.select([self.ws.sock], [], [], timeout)
            if not readable:
                return None
        self.deadline = time.monotonic() + 60
        msg: Dict[str, Any] = self._recv()
        return msg if "method" in msg else None


class Stats:
    def __init__(self) -> None:
        self.started_at: float = time.time()
        self.streams: List[GatewayStream] = []
        self.ops_received: Counter = Counter()
        self.ops_sent: Counter = Counter()
        self.dispatch: Counter = Counter()
        self.dispatch_keys: Dict[str, List[str]] = {}
        self.guilds: Dict[str, Dict[str, Any]] = {}
        self.dm_messages: Counter = Counter()
        self.unknown_guild_events: Counter = Counter()
        self.decode_errors: Counter = Counter()
        self.ready_seen: bool = False
        self.login_page_seen: bool = False
        # --log-messages only: open JSONL file, and name caches for its lines.
        self.message_log: Optional[Any] = None
        self.channels: Dict[str, str] = {}
        self.users: Dict[str, str] = {}

    def note_user(self, u: Any) -> None:
        if isinstance(u, dict) and u.get("id") is not None:
            name = u.get("username")
            if name:
                self.users[str(u["id"])] = str(name)

    def note_channel(self, c: Any) -> None:
        if not isinstance(c, dict) or c.get("id") is None:
            return
        name = c.get("name")
        if not name:
            # DMs and group DMs have no name; use the recipients' usernames.
            names: List[str] = []
            for r in c.get("recipients") or []:
                self.note_user(r)
                if isinstance(r, dict) and r.get("username"):
                    names.append(str(r["username"]))
            for rid in c.get("recipient_ids") or []:
                if str(rid) in self.users:
                    names.append(self.users[str(rid)])
            name = "DM: " + ", ".join(names) if names else None
        if name:
            self.channels[str(c["id"])] = str(name)

    def log_message(self, t: str, d: Dict[str, Any]) -> None:
        if self.message_log is None:
            return
        author: Dict[str, Any] = d.get("author") if isinstance(d.get("author"), dict) else {}
        self.note_user(author)
        gid = d.get("guild_id")
        cid = d.get("channel_id")
        line: Dict[str, Any] = {
            "at": time.strftime("%Y-%m-%dT%H:%M:%S", time.localtime()),
            "t": t,
            "guild": (self.guilds.get(str(gid), {}).get("name") if gid is not None else None),
            "guild_id": str(gid) if gid is not None else None,
            "channel": self.channels.get(str(cid)) if cid is not None else None,
            "channel_id": str(cid) if cid is not None else None,
            "id": str(d["id"]) if d.get("id") is not None else None,
            "ids": [str(i) for i in d.get("ids") or []] or None,
            "author": author.get("username"),
            "author_display": author.get("global_name"),
            "author_id": str(author["id"]) if author.get("id") is not None else None,
            "content": d.get("content"),
            "type": d.get("type"),
            "flags": d.get("flags"),
            "edited_timestamp": d.get("edited_timestamp"),
            "attachments": len(d.get("attachments") or []) if "attachments" in d else None,
            "embeds": len(d.get("embeds") or []) if "embeds" in d else None,
        }
        if t == "MESSAGE_UPDATE":
            line["keys"] = sorted(str(k) for k in d.keys())
        self.message_log.write(json.dumps({k: v for k, v in line.items() if v is not None}, ensure_ascii=False) + "\n")
        self.message_log.flush()

    def guild(self, gid: str) -> Dict[str, Any]:
        g: Optional[Dict[str, Any]] = self.guilds.get(gid)
        if g is None:
            g = {
                "name": None,
                "large": None,
                "member_count": None,
                "MESSAGE_CREATE": 0,
                "MESSAGE_UPDATE": 0,
                "MESSAGE_DELETE": 0,
                "PASSIVE_UPDATE": 0,
                "client_subscribed": 0,
            }
            self.guilds[gid] = g
        return g

    def note_guild_object(self, obj: Dict[str, Any]) -> None:
        if not isinstance(obj, dict) or obj.get("id") is None:
            return
        g = self.guild(str(obj["id"]))
        props: Dict[str, Any] = obj.get("properties") if isinstance(obj.get("properties"), dict) else {}
        name = obj.get("name") or props.get("name")
        if name:
            g["name"] = name
        if obj.get("large") is not None:
            g["large"] = bool(obj["large"])
        if obj.get("member_count") is not None:
            g["member_count"] = obj["member_count"]

    def on_received(self, payload: Any) -> None:
        if not isinstance(payload, dict):
            return
        op = payload.get("op")
        self.ops_received[str(op)] += 1
        if op != 0:
            return
        t: str = str(payload.get("t"))
        d: Any = payload.get("d")
        self.dispatch[t] += 1
        if t not in self.dispatch_keys and isinstance(d, dict):
            self.dispatch_keys[t] = sorted(str(k) for k in d.keys())
        if not isinstance(d, dict):
            return

        if t == "READY":
            self.ready_seen = True
            for u in d.get("users") or []:
                self.note_user(u)
            for g in d.get("guilds") or []:
                self.note_guild_object(g)
                for c in (g.get("channels") or []) + (g.get("threads") or []) if isinstance(g, dict) else []:
                    self.note_channel(c)
            for c in d.get("private_channels") or []:
                self.note_channel(c)
        elif t in ("GUILD_CREATE", "GUILD_UPDATE"):
            self.note_guild_object(d)
            for c in (d.get("channels") or []) + (d.get("threads") or []):
                self.note_channel(c)
        elif t in ("CHANNEL_CREATE", "CHANNEL_UPDATE", "THREAD_CREATE", "THREAD_UPDATE"):
            self.note_channel(d)
        elif t in ("MESSAGE_CREATE", "MESSAGE_UPDATE", "MESSAGE_DELETE"):
            gid = d.get("guild_id")
            if gid is None:
                self.dm_messages[t] += 1
            else:
                self.guild(str(gid))[t] += 1
            self.log_message(t, d)
        elif t == "MESSAGE_DELETE_BULK":
            self.log_message(t, d)
            gid = d.get("guild_id")
            n: int = len(d.get("ids") or [])
            if gid is None:
                self.dm_messages["MESSAGE_DELETE"] += n
            else:
                self.guild(str(gid))["MESSAGE_DELETE"] += n
        elif t.startswith("PASSIVE_UPDATE"):
            gid = d.get("guild_id")
            if gid is None:
                self.unknown_guild_events[t] += 1
            else:
                self.guild(str(gid))["PASSIVE_UPDATE"] += 1

    def on_sent(self, raw: bytes, encoding: str) -> None:
        # Peek at the opcode without decoding token-bearing frames. JSON frames
        # start with {"op":N; for ETF, decode only if the op is not secret,
        # which needs a decode first, so ETF sends are counted as "etf" only
        # unless they are small (subscriptions are small, IDENTIFY is not).
        if encoding == "etf":
            if len(raw) > 4096:
                self.ops_sent["etf-large"] += 1
                return
            try:
                payload = etf_decode(raw)
            except Exception:
                self.ops_sent["undecodable"] += 1
                return
        else:
            try:
                payload = json.loads(raw.decode("utf-8"))
            except Exception:
                self.ops_sent["undecodable"] += 1
                return
        if not isinstance(payload, dict):
            return
        op = payload.get("op")
        self.ops_sent[str(op)] += 1
        if op in SECRET_SEND_OPS:
            return
        d: Any = payload.get("d")
        if op == 14 and isinstance(d, dict) and d.get("guild_id") is not None:
            self.guild(str(d["guild_id"]))["client_subscribed"] += 1
        elif op == 37 and isinstance(d, dict) and isinstance(d.get("subscriptions"), dict):
            for gid in d["subscriptions"].keys():
                self.guild(str(gid))["client_subscribed"] += 1

    def report(self) -> Dict[str, Any]:
        return {
            "started_at": self.started_at,
            "written_at": time.time(),
            "elapsed_sec": round(time.time() - self.started_at),
            "zstandard_available": zstandard is not None,
            "ready_seen": self.ready_seen,
            "login_page_seen": self.login_page_seen,
            "gateway_connections": [s.describe() for s in self.streams],
            "ops_received": dict(self.ops_received),
            "ops_sent": dict(self.ops_sent),
            "dispatch_counts": dict(self.dispatch.most_common()),
            "dispatch_keys": self.dispatch_keys,
            "dm_messages": dict(self.dm_messages),
            "events_without_guild": dict(self.unknown_guild_events),
            "decode_errors": dict(self.decode_errors),
            "guilds": self.guilds,
        }


def is_gateway_url(url: str) -> bool:
    # gateway.discord.gg or a regional gateway-<region>.discord.gg. The QR-code
    # login socket (remote-auth-gateway.discord.gg) is a different protocol and
    # is deliberately not matched.
    host: str = (urlparse(url).hostname or "").lower()
    return host.startswith("gateway") and host.endswith(".discord.gg")


def write_report(path: str, stats: Stats) -> None:
    tmp: str = path + ".tmp"
    with open(tmp, "w") as f:
        json.dump(stats.report(), f, indent=2, sort_keys=True)
    os.replace(tmp, path)


def print_summary(stats: Stats) -> None:
    out = sys.stdout
    out.write("\n=== Discord gateway probe: {} min ===\n".format(round((time.time() - stats.started_at) / 60)))
    if not stats.ready_seen:
        out.write("READY was never received.")
        if stats.login_page_seen:
            out.write(" The client landed on the login page: run with --login first.")
        out.write("\n")
    for s in stats.streams:
        d = s.describe()
        out.write("gateway: compress={} encoding={} v={}{}\n".format(
            d["compress"], d["encoding"], d["v"], (" BROKEN: " + d["broken"]) if d["broken"] else ""))
    out.write("DM/group DM events: {}\n".format(dict(stats.dm_messages) or "none"))
    if stats.unknown_guild_events:
        out.write("events with no guild_id: {}\n".format(dict(stats.unknown_guild_events)))
    if stats.decode_errors:
        out.write("decode errors: {}\n".format(dict(stats.decode_errors)))

    rows = sorted(stats.guilds.items(), key=lambda kv: (-kv[1]["MESSAGE_CREATE"], -kv[1]["PASSIVE_UPDATE"]))
    out.write("\n{:<32} {:>6} {:>8} {:>7} {:>7} {:>7} {:>8} {:>5}\n".format(
        "server", "large", "members", "create", "update", "delete", "passive", "subs"))
    for gid, g in rows:
        name: str = (g["name"] or gid)[:32]
        out.write("{:<32} {:>6} {:>8} {:>7} {:>7} {:>7} {:>8} {:>5}\n".format(
            name, str(g["large"]), str(g["member_count"] if g["member_count"] is not None else "?"),
            g["MESSAGE_CREATE"], g["MESSAGE_UPDATE"], g["MESSAGE_DELETE"], g["PASSIVE_UPDATE"], g["client_subscribed"]))
    out.write("\nA server with passive > 0 and create == 0 is one where messages are NOT\n"
              "delivered while idle. 'subs' counts subscription requests the client sent.\n")
    out.flush()


def launch(binary: str, profile: str, port: int, headless: bool) -> Tuple[subprocess.Popen, str]:
    os.makedirs(profile, exist_ok=True)
    cmd: List[str] = chrome_command(binary, profile, port, headless, "about:blank")
    cmd[-1:-1] = EXTRA_CHROME_FLAGS
    proc = subprocess.Popen(cmd, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                            stderr=subprocess.DEVNULL, start_new_session=True)
    deadline: float = time.monotonic() + 30
    while time.monotonic() < deadline:
        if proc.poll() is not None:
            raise FetchError("Chrome exited during startup (code {}); is a --login window still open "
                             "on this profile?".format(proc.returncode))
        ws_url: Optional[str] = devtools_ws_url(port)
        if ws_url:
            return proc, ws_url
        time.sleep(0.25)
    stop_process(proc)
    raise FetchError("Chrome did not open the debugging port {} in time".format(port))


def prepare_session(cdp: ProbeCdp, session_id: str, user_agent: Optional[str]) -> None:
    if user_agent:
        cdp.cmd("Network.setUserAgentOverride", {"userAgent": user_agent}, session_id)
    cdp.cmd("Network.enable", {}, session_id)


def run_probe(args: argparse.Namespace, binary: str, profile: str) -> int:
    stop_requested: List[bool] = [False]

    def on_signal(signum: int, frame: Any) -> None:
        stop_requested[0] = True

    signal.signal(signal.SIGINT, on_signal)
    signal.signal(signal.SIGTERM, on_signal)

    stats = Stats()
    streams: Dict[Tuple[str, str], GatewayStream] = {}
    lock_file = acquire_lock(profile.rstrip("/") + ".lock", 5)
    if args.log_messages:
        # Appended, so several runs accumulate in one file.
        stats.message_log = open(args.log_messages, "a", encoding="utf-8")
        sys.stderr.write("Logging message events (with content) to {}\n".format(args.log_messages))
    proc: Optional[subprocess.Popen] = None
    cdp: Optional[ProbeCdp] = None
    try:
        if devtools_ws_url(args.port):
            raise FetchError("something is already listening on port {}; pick another --port".format(args.port))
        proc, ws_url = launch(binary, profile, args.port, not args.visible)
        cdp = ProbeCdp(ws_url, time.monotonic() + 30)

        target_id: str = cdp.cmd("Target.createTarget", {"url": "about:blank"})["targetId"]
        page_session: str = cdp.cmd("Target.attachToTarget", {"targetId": target_id, "flatten": True})["sessionId"]

        # Same UA fix as browser_fetch.py: drop the "HeadlessChrome" marker.
        ua: str = cdp.cmd("Browser.getVersion").get("userAgent", "")
        user_agent: Optional[str] = ua.replace("HeadlessChrome", "Chrome") if "HeadlessChrome" in ua else None

        prepare_session(cdp, page_session, user_agent)
        cdp.cmd("Page.enable", {}, page_session)
        # Attach to workers too, in case the gateway socket ever moves off the
        # main thread. They start paused so Network is enabled before they run.
        cdp.cmd("Target.setAutoAttach", {"autoAttach": True, "waitForDebuggerOnStart": True, "flatten": True}, page_session)
        cdp.cmd("Page.navigate", {"url": DISCORD_APP_URL}, page_session)
        sys.stderr.write("Probe running on {} for {} s. Ctrl+C to stop early.\n".format(DISCORD_APP_URL, args.duration))

        end_at: float = time.monotonic() + args.duration
        next_report: float = time.monotonic() + args.report_every
        ready_deadline: float = time.monotonic() + 120
        warned_no_ready: bool = False

        while not stop_requested[0] and time.monotonic() < end_at:
            if proc.poll() is not None:
                raise FetchError("Chrome exited unexpectedly (code {})".format(proc.returncode))
            now: float = time.monotonic()
            if now >= next_report:
                write_report(args.report, stats)
                next_report = now + args.report_every
            if not stats.ready_seen and not warned_no_ready and now >= ready_deadline:
                warned_no_ready = True
                sys.stderr.write("No READY after 2 minutes{}.\n".format(
                    "; the client is on the login page, run --login" if stats.login_page_seen else ""))
                if stats.login_page_seen:
                    break

            try:
                ev: Optional[Dict[str, Any]] = cdp.poll(1.0)
            except InterruptedError:
                continue
            if ev is None:
                continue
            method: str = ev.get("method", "")
            params: Dict[str, Any] = ev.get("params", {})
            sid: str = ev.get("sessionId", "")

            if method == "Target.attachedToTarget":
                child: str = params.get("sessionId", "")
                try:
                    prepare_session(cdp, child, user_agent)
                finally:
                    cdp.cmd("Runtime.runIfWaitingForDebugger", {}, child)
            elif method == "Page.frameNavigated" and sid == page_session:
                frame: Dict[str, Any] = params.get("frame", {})
                if not frame.get("parentId") and "/login" in frame.get("url", ""):
                    stats.login_page_seen = True
            elif method == "Page.navigatedWithinDocument" and sid == page_session:
                # Discord routes /app -> /login with history.pushState, which
                # only shows up as a same-document navigation.
                if params.get("frameId") == target_id and "/login" in params.get("url", ""):
                    stats.login_page_seen = True
            elif method == "Network.webSocketCreated":
                url: str = params.get("url", "")
                if is_gateway_url(url):
                    stream = GatewayStream(url)
                    streams[(sid, params["requestId"])] = stream
                    stats.streams.append(stream)
            elif method == "Network.webSocketClosed":
                stream = streams.pop((sid, params.get("requestId", "")), None)
                if stream is not None:
                    stream.closed_at = time.time()
            elif method in ("Network.webSocketFrameReceived", "Network.webSocketFrameSent"):
                stream = streams.get((sid, params.get("requestId", "")))
                if stream is None:
                    continue
                resp: Dict[str, Any] = params.get("response", {})
                data: str = resp.get("payloadData", "")
                raw: bytes = data.encode("utf-8") if resp.get("opcode") == 1 else base64.b64decode(data)
                if method == "Network.webSocketFrameSent":
                    stats.on_sent(raw, stream.encoding)
                    continue
                try:
                    payload = stream.decode_received(raw)
                except Exception as e:
                    stats.decode_errors[type(e).__name__] += 1
                    continue
                if payload is not None:
                    stats.on_received(payload)

        return 0
    finally:
        write_report(args.report, stats)
        print_summary(stats)
        sys.stderr.write("Report written to {}\n".format(args.report))
        if cdp is not None:
            cdp.deadline = time.monotonic() + 5
            try:
                cdp.send("Browser.close")
            except (FetchError, OSError):
                pass
        if proc is not None:
            stop_process(proc)
        if cdp is not None:
            cdp.close()
        lock_file.close()
        if stats.message_log is not None:
            stats.message_log.close()


def main() -> int:
    parser = argparse.ArgumentParser(description="Measure which Discord servers deliver messages to an idle web client.")
    parser.add_argument("--profile", default=DEFAULT_PROFILE, help="Chrome user-data-dir")
    parser.add_argument("--port", type=int, default=DEFAULT_PORT, help="remote-debugging port on 127.0.0.1")
    parser.add_argument("--binary", default=None, help="Chrome/Chromium executable")
    parser.add_argument("--duration", type=float, default=DEFAULT_DURATION, help="seconds to watch")
    parser.add_argument("--report", default=DEFAULT_REPORT, help="JSON report path")
    parser.add_argument("--report-every", type=float, default=300, help="seconds between report rewrites")
    parser.add_argument("--visible", action="store_true", help="run with a window instead of headless")
    parser.add_argument("--log-messages", nargs="?", const=DEFAULT_MESSAGE_LOG, default=None, metavar="PATH",
                        help="also append every message event, with content, to a JSONL file "
                             "(default path: ./probe_messages.jsonl next to this script)")
    parser.add_argument("--login", action="store_true", help="open a visible window on the profile to log into Discord")
    args = parser.parse_args()

    profile: str = os.path.abspath(args.profile)
    args.report = os.path.abspath(args.report)
    if args.log_messages:
        args.log_messages = os.path.abspath(args.log_messages)
    try:
        binary: str = find_binary(args.binary)
        if args.login:
            os.makedirs(profile, exist_ok=True)
            sys.stderr.write("Opening Chrome on profile {}. Log into Discord, then close the window.\n".format(profile))
            return subprocess.call(chrome_command(binary, profile, None, False, DISCORD_LOGIN_URL))
        return run_probe(args, binary, profile)
    except FetchError as e:
        sys.stderr.write("error: {}\n".format(e))
        return 1


if __name__ == "__main__":
    sys.exit(main())
