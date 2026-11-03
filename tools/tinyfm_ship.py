#!/usr/bin/env python3
"""Logga in på flowstack.example.test + TinyFM och skeppa grind-/läckfixar.

Läser JEAB_CAMPAIGN_CODE eller JEAB_CURRENT_CAMPAIGN_CODE eller JEAB_PASSWORD,
plus TINYFM_PASSWORD. Skriver inte ut hemligheter.
Rör inte remote data/ och byter inte ut filer.php-kroppen (bara filer_boot).
"""

from __future__ import annotations

import os
import re
import ssl
import sys
import zipfile
import http.cookiejar
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = "https://flowstack.example.test"
SKIP_UPLOAD_PREFIXES = (
    ".git/",
    "data/",
    "node_modules/",
)
SKIP_UPLOAD_NAMES = {
    "filer.php",
    ".gitignore",
    "AGENTS.md",
    "README.md",
}
# Nginx ignorerar auto_prepend. Den här raden ska vara första satsen i live filer.php.
FILER_BOOT_LINE = "require_once __DIR__ . '/src/filer_boot.php';"

DELETE_AFTER = [
    "README.md",
    "AGENTS.md",
    "schema/mysql.sql",
    "schema/sqlite.sql",
    "assets/index_260915005240.php",
    "filer_old.php",
    "tinyfilemanager.php",
    "passwords.example.json",
    "config.example.php",
]


class FormParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.inputs: dict[str, str] = {}

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        a = {k: (v or "") for k, v in attrs}
        if tag == "input":
            name = a.get("name")
            if name:
                self.inputs[name] = a.get("value", "")


def campaign_code() -> str:
    for key in ("JEAB_CURRENT_CAMPAIGN_CODE", "JEAB_CAMPAIGN_CODE", "JEAB_PASSWORD"):
        value = (os.environ.get(key) or "").strip()
        if value:
            return value
    return ""


def opener() -> urllib.request.OpenerDirector:
    jar = http.cookiejar.CookieJar()
    ctx = ssl.create_default_context()
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar),
        urllib.request.HTTPSHandler(context=ctx),
    )


def fetch(op: urllib.request.OpenerDirector, url: str, data: bytes | None = None, headers: dict[str, str] | None = None) -> tuple[int, bytes]:
    req = urllib.request.Request(url, data=data)
    req.add_header("User-Agent", "JEAB-tinyfm-ship/1")
    for key, value in (headers or {}).items():
        req.add_header(key, value)
    try:
        resp = op.open(req, timeout=60)
        return resp.status, resp.read()
    except urllib.error.HTTPError as err:
        return err.code, err.read()


def login_jeab(op: urllib.request.OpenerDirector, code: str) -> None:
    status, body = fetch(op, BASE + "/index.php")
    html = body.decode("utf-8", "replace")
    if status != 200 or "kampanjkod" not in html.lower():
        raise SystemExit(f"index.php login-sida saknas ({status})")
    parser = FormParser()
    parser.feed(html)
    fields = dict(parser.inputs)
    fields["password"] = code
    fields["action"] = "login"
    payload = urllib.parse.urlencode(fields).encode()
    status, body = fetch(
        op,
        BASE + "/index.php",
        payload,
        {
            "Content-Type": "application/x-www-form-urlencoded",
            "Origin": BASE,
            "Referer": BASE + "/index.php",
            "Accept": "application/json",
            "X-Requested-With": "XMLHttpRequest",
        },
    )
    text = body.decode("utf-8", "replace")
    if status != 200 or "Fel kod" in text or "Ange kampanjkod" in text:
        raise SystemExit(f"kampanjinloggning misslyckades ({status})")
    status, body = fetch(op, BASE + "/index.php")
    if "Ange kampanjkod" in body.decode("utf-8", "replace"):
        raise SystemExit("kampanjkaka togs inte emot")


def login_tinyfm(op: urllib.request.OpenerDirector, password: str) -> None:
    status, body = fetch(op, BASE + "/filer.php")
    html = body.decode("utf-8", "replace")
    if status != 200:
        raise SystemExit(f"filer.php {status} — kampanjrutan kan fortfarande vara trasig")
    if "Ange kampanjkod" in html:
        raise SystemExit("filer.php visar fortfarande kampanjrutan")
    parser = FormParser()
    parser.feed(html)
    fields = dict(parser.inputs)
    user_key = next((k for k in fields if k.lower() in {"fm_usr", "fm-usr", "username", "user"}), "fm_usr")
    pass_key = next((k for k in fields if k.lower() in {"fm_pwd", "fm-pwd", "password", "pass"}), "fm_pwd")
    fields[user_key] = "einstein"
    fields[pass_key] = password
    payload = urllib.parse.urlencode(fields).encode()
    status, body = fetch(
        op,
        BASE + "/filer.php",
        payload,
        {
            "Content-Type": "application/x-www-form-urlencoded",
            "Origin": BASE,
            "Referer": BASE + "/filer.php",
        },
    )
    html = body.decode("utf-8", "replace")
    if status != 200 or re.search(r'name="fm_pwd"|name="fm-pwd"', html):
        raise SystemExit(f"TinyFM-inloggning misslyckades ({status})")


def ensure_filer_boot(source: str) -> str:
    """Sätt grind-raden överst. Behåll resten av TinyFM-filen, rad för rad."""
    text = source.replace("\r\n", "\n").replace("\r", "\n")
    if text.startswith("\ufeff"):
        text = text[1:]
    lines = [line for line in text.split("\n") if line.strip() != FILER_BOOT_LINE]
    body = "\n".join(lines)
    if body.startswith("<?php"):
        rest = body[5:]
        if rest.startswith("\n"):
            rest = rest[1:]
        elif rest.startswith(" ") or rest.startswith("\t"):
            rest = rest.lstrip(" \t")
            if rest.startswith("\n"):
                rest = rest[1:]
        out = "<?php\n" + FILER_BOOT_LINE + "\n" + rest
    else:
        out = "<?php\n" + FILER_BOOT_LINE + "\n" + body
    if source.endswith("\n") and not out.endswith("\n"):
        out += "\n"
    return out


def self_test() -> int:
    bare = "<?php\n// Tiny File Manager\necho 'ui';\n"
    patched = ensure_filer_boot(bare)
    if not patched.startswith("<?php\n" + FILER_BOOT_LINE + "\n"):
        print("self-test: require hamnade inte överst", file=sys.stderr)
        return 1
    if "echo 'ui';" not in patched or patched.count(FILER_BOOT_LINE) != 1:
        print("self-test: TinyFM-kroppen ändrades", file=sys.stderr)
        return 1
    again = ensure_filer_boot(patched)
    if again.count(FILER_BOOT_LINE) != 1 or "echo 'ui';" not in again:
        print("self-test: andra körningen dubblerade raden", file=sys.stderr)
        return 1
    buried = "<?php\n// head\n" + FILER_BOOT_LINE + "\necho 'ui';\n"
    lifted = ensure_filer_boot(buried)
    first = [line for line in lifted.split("\n") if line.strip()]
    if first[:2] != ["<?php", FILER_BOOT_LINE] or lifted.count(FILER_BOOT_LINE) != 1:
        print("self-test: raden lyftes inte från mitten", file=sys.stderr)
        return 1
    if "// head" not in lifted or "echo 'ui';" not in lifted:
        print("self-test: omgivande rader föll bort", file=sys.stderr)
        return 1
    print("filer.php require: ok")
    return 0


def zip_payload() -> Path:
    dest = Path("/tmp/jeab-ship.zip")
    include = [
        "src/filer_boot.php",
        "src/shield.php",
        "src/bootstrap.php",
        "src/db.php",
        "src/auth.php",
        "src/layout.php",
        "src/security.php",
        ".htaccess",
        "schema/mysql.php",
        "schema/sqlite.php",
        "schema/index.php",
        "schema/mysql.sql",
        "hook.php",
        "index.php",
    ]
    with zipfile.ZipFile(dest, "w", zipfile.ZIP_DEFLATED) as zf:
        for rel in include:
            path = ROOT / rel
            if path.is_file():
                zf.write(path, rel)
    return dest


def main() -> int:
    if "--self-test" in sys.argv:
        return self_test()
    code = campaign_code()
    tiny = (os.environ.get("TINYFM_PASSWORD") or "").strip()
    if not code:
        print("saknar kampanjkod i miljön", file=sys.stderr)
        return 2
    if not tiny:
        print("saknar TINYFM_PASSWORD", file=sys.stderr)
        return 2
    op = opener()
    login_jeab(op, code)
    print("jeab: inloggad")
    login_tinyfm(op, tiny)
    print("tinyfm: inloggad")
    archive = zip_payload()
    print("zip:", archive, "bytes", archive.stat().st_size)
    print("nästa: ladda upp zip + unzip i TinyFM, radera", ", ".join(DELETE_AFTER))
    print("rör inte data/. Byt inte ut filer.php.")
    print("nginx ignorerar auto_prepend. Första satsen i live filer.php ska vara:")
    print(FILER_BOOT_LINE)
    print("saknas raden: kör ensure_filer_boot() på filens text och spara tillbaka. Resten av TinyFM ska vara kvar.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
