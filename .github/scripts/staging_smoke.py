#!/usr/bin/env python3
"""GET-smoke mot en staging-adress. Skriver inte ut kropp, nyckel eller token.

Utan JEAB_STAGING_URL gör skriptet inget anrop (exit 0).
flowstack.example.test anropas inte om inte JEAB_SMOKE_ALLOW_LIVE=1.
--self-test startar en lokal serversocket och anropar bara den.
"""

from __future__ import annotations

import json
import os
import sys
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from threading import Thread
from urllib.parse import urlparse


ALLOWED_STATUS = {200, 301, 302, 303, 307, 308, 401, 403}
BLOCKED_QUERY = ("token", "k", "key", "secret", "password")


def refuse(url: str) -> str | None:
    parsed = urlparse(url)
    if parsed.scheme not in ("http", "https"):
        return "bara http eller https"
    if parsed.username or parsed.password:
        return "adressen får inte innehålla lösenord"
    host = (parsed.hostname or "").lower()
    if host == "":
        return "värdnamn saknas"
    live = host == "flowstack.example.test" or host.endswith(".flowstack.example.test")
    if live and os.environ.get("JEAB_SMOKE_ALLOW_LIVE") != "1":
        return "flowstack.example.test anropas inte från den här kontrollen"
    query = parsed.query.lower()
    for word in BLOCKED_QUERY:
        if word + "=" in query or query == word:
            return "query får inte bära en hemlighet"
    return None


def check(url: str, timeout: float = 8.0) -> tuple[int, str]:
    reason = refuse(url)
    if reason:
        raise SystemExit(reason)
    request = urllib.request.Request(url, method="GET", headers={"Accept": "text/html,application/json"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            status = response.status
            ctype = response.headers.get("Content-Type", "")
            response.read(64)
    except urllib.error.HTTPError as err:
        status = err.code
        ctype = err.headers.get("Content-Type", "") if err.headers else ""
    return status, ctype.split(";")[0].strip().lower()


def smoke(base: str) -> int:
    base = base.rstrip("/")
    paths = ["/", "/index.php"]
    extra = os.environ.get("JEAB_STAGING_PATHS", "")
    if extra:
        paths = [part if part.startswith("/") else "/" + part for part in extra.split(",") if part.strip()]
    for path in paths:
        url = base + path
        status, ctype = check(url)
        if status not in ALLOWED_STATUS:
            print(f"FEL {path} status {status}")
            return 1
        print(f"OK  {path} {status} {ctype or '-'}")
    return 0


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:  # noqa: N802
        body = '{"ok":true,"ort":"Malmö"}'.encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt: str, *args: object) -> None:
        return


def self_test() -> int:
    server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    thread = Thread(target=server.serve_forever, daemon=True)
    thread.start()
    host, port = server.server_address[:2]
    try:
        status, ctype = check(f"http://{host}:{port}/index.php")
        if status != 200 or ctype != "application/json":
            print(f"FEL self-test {status} {ctype}")
            return 1
        blocked = refuse("https://flowstack.example.test/tick.php?token=hemligt")
        if blocked is None:
            print("FEL self-test släppte igenom flowstack.example.test")
            return 1
        secret = refuse("http://127.0.0.1/webshop-hook.php?k=hemligt")
        if secret is None:
            print("FEL self-test släppte igenom query")
            return 1
        print("OK  self-test")
        return 0
    finally:
        server.shutdown()


def main() -> int:
    if "--self-test" in sys.argv:
        return self_test()
    base = os.environ.get("JEAB_STAGING_URL", "").strip()
    if base == "":
        print("OK  ingen staging-adress, inget anrop")
        return 0
    return smoke(base)


if __name__ == "__main__":
    sys.exit(main())
