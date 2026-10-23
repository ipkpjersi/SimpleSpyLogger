#!/usr/bin/env python3
"""
SimpleSpyLogger - Reddit fetch helper used by download.php.

Reddit's "network security" layer blocks plain curl/OpenSSL by TLS and HTTP/2
fingerprint (403 "You've been blocked by network security"), even with a valid
session cookie and browser-like headers. curl_cffi impersonates a real Chrome
handshake, which gets through.

Usage: fetch.py <url> <impersonate-target>
  The Cookie header is read from the REDDIT_FETCH_COOKIE env var (not argv) so
  the session cookie does not show up in the process list.

Prints one JSON object to stdout:
  {"code": <http status>, "body": "<response body>"}  on any HTTP response
  {"error": "<message>"}                               on a transport failure
"""

import json
import os
import sys


def main() -> int:
    if len(sys.argv) < 3:
        print(json.dumps({"error": "usage: fetch.py <url> <impersonate-target>"}))
        return 2

    url = sys.argv[1]
    target = sys.argv[2]
    cookie = os.environ.get("REDDIT_FETCH_COOKIE", "")

    try:
        from curl_cffi import requests
    except ImportError:
        print(json.dumps({"error": "python module curl_cffi not installed (pip install --user curl_cffi)"}))
        return 3

    headers = {"Cookie": cookie} if cookie else {}
    try:
        resp = requests.get(url, impersonate=target, headers=headers, timeout=30, allow_redirects=True)
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        return 4

    print(json.dumps({"code": resp.status_code, "body": resp.text}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
