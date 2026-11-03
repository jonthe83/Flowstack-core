#!/usr/bin/env python3
"""Skapa privat/pnr-hmac.key (syskon till public_html) via FTP.

Nyckeln (32 slumpbytes, hex) skapas i runnern och skrivs aldrig ut.
Finns filen redan skrivs ingenting. Uppladdning till ett tillfälligt namn,
kontroll av storlek och sha256, chmod 600 och sedan byte till slutnamnet.
"""

from __future__ import annotations

import hashlib
import io
import os
import secrets
import sys

_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
if _SCRIPT_DIR not in sys.path:
    sys.path.insert(0, _SCRIPT_DIR)

from omtago_key_place import (  # noqa: E402
    DEFAULT_ROOT,
    PlaceError,
    ensure_privat,
    open_ftp,
    redact,
    webroot_parent,
)
from loopia_deploy import FTP_ERRORS, read_loopia_ftp_env  # noqa: E402

DEST = "pnr-hmac.key"


def exists(ftp: object, name: str) -> bool:
    try:
        names = ftp.nlst()
    except FTP_ERRORS:
        names = []
    if name in [n.rsplit("/", 1)[-1] for n in names]:
        return True
    try:
        return ftp.size(name) is not None
    except FTP_ERRORS:
        return False


def place(ftp: object, key: str) -> int:
    data = (key + "\n").encode("ascii")
    want = hashlib.sha256(data).hexdigest()
    ensure_privat(ftp, webroot_parent(os.environ.get("LOOPIA_FTP_ROOT", DEFAULT_ROOT)))
    if exists(ftp, DEST):
        print(f"privat/{DEST} finns redan. Ingen fil skrevs.", flush=True)
        return 2
    tmp = f".{DEST}.tmp-{secrets.token_hex(4)}"
    ftp.storbinary(f"STOR {tmp}", io.BytesIO(data), blocksize=8192)
    ftp.voidcmd(f"SITE CHMOD 600 {tmp}")
    size = ftp.size(tmp)
    buf = io.BytesIO()
    ftp.retrbinary(f"RETR {tmp}", buf.write)
    if size is None or int(size) != len(data) or hashlib.sha256(buf.getvalue()).hexdigest() != want:
        raise PlaceError("Storlek eller sha stämde inte efter uppladdning. Slutnamnet rördes inte.")
    if exists(ftp, DEST):
        print(f"privat/{DEST} dök upp under körningen. Slutnamnet rördes inte; temp ligger kvar.", flush=True)
        return 2
    ftp.rename(tmp, DEST)
    size2 = ftp.size(DEST)
    buf2 = io.BytesIO()
    ftp.retrbinary(f"RETR {DEST}", buf2.write)
    if size2 is None or int(size2) != len(data) or hashlib.sha256(buf2.getvalue()).hexdigest() != want:
        raise PlaceError("Slutfilen stämde inte efter bytet.")
    print(f"skrev privat/{DEST} bytes={int(size2)} sha ok chmod=600", flush=True)
    return 0


def main() -> int:
    key = secrets.token_hex(32)
    host, user, password, _ = read_loopia_ftp_env()
    hidden = (key, host, user, password)
    ftp = None
    try:
        ftp = open_ftp()
        return place(ftp, key)
    except PlaceError as err:
        print(redact(str(err), *hidden), file=sys.stderr, flush=True)
        return 1
    except Exception as err:
        print(redact(f"Kunde inte skriva nyckelfilen ({type(err).__name__}).", *hidden), file=sys.stderr, flush=True)
        return 1
    finally:
        key = ""
        if ftp is not None:
            try:
                ftp.quit()
            except Exception:
                pass


if __name__ == "__main__":
    raise SystemExit(main())
