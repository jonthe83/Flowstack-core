#!/usr/bin/env python3
"""Skriv en repo-secret till privat/ via FTP, som syskon till public_html.

Standard: OMTAGO_API_KEY → privat/omtago_api_key.
JEAB_PLACE_SECRET_ENV och JEAB_PLACE_DEST byter secret och filnamn
(tick-token använder JEAB_TICK_TOKEN → privat/jeab_tick_token).

Inloggningen är loopia_deploy.connect_with_credentials: samma trimning av
LOOPIA_FTP_HOST, LOOPIA_FTP_USER och LOOPIA_FTP_PASS och samma FTPS
(SessionFTP, PROT P, passivt läge) som deploy-loopia.yml. Ingen egen
FTP-klient.

Värdet skrivs aldrig till stdout, stderr eller undantagstext. chmod 600.
"""

from __future__ import annotations

import io
import os
import posixpath
import re
import sys

_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
if _SCRIPT_DIR not in sys.path:
    sys.path.insert(0, _SCRIPT_DIR)

from loopia_deploy import (  # noqa: E402
    FTP_ERRORS,
    DeployError,
    connect_with_credentials,
    read_loopia_ftp_env,
)

DEST_NAME = "omtago_api_key"
DEFAULT_SECRET_ENV = "OMTAGO_API_KEY"
DEFAULT_ROOT = "/flowstack.example.test/public_html"


class PlaceError(RuntimeError):
    pass


def redact(text: str, *secrets: str) -> str:
    cleaned = text
    for secret in secrets:
        if secret:
            cleaned = cleaned.replace(secret, "[hemlighet]")
    return cleaned


def webroot_parent(root: str) -> str:
    raw = (root or DEFAULT_ROOT).strip() or DEFAULT_ROOT
    if not raw.startswith("/"):
        raw = "/" + raw
    path = posixpath.normpath(raw)
    if posixpath.basename(path) != "public_html":
        raise PlaceError("FTP-roten måste sluta med public_html. Ingen fil skrevs.")
    parent = posixpath.dirname(path)
    if parent in ("", "/"):
        raise PlaceError("Hittar ingen katalog ovanför public_html. Ingen fil skrevs.")
    if "public_html" in [part for part in parent.split("/") if part]:
        raise PlaceError("privat skulle hamna under public_html. Ingen fil skrevs.")
    return parent


def resolved_dest() -> str:
    """Filnamn under privat/. JEAB_PLACE_DEST byter mål, annars Omtago-nyckeln."""
    name = os.environ.get("JEAB_PLACE_DEST", "").strip() or DEST_NAME
    if re.fullmatch(r"[a-z0-9_]{1,64}", name) is None:
        raise PlaceError("Ogiltigt filnamn. Ingen fil skrevs.")
    return name


def resolved_secret_env() -> str:
    """Repo-secret som ska skrivas. JEAB_PLACE_SECRET_ENV byter namn."""
    name = os.environ.get("JEAB_PLACE_SECRET_ENV", "").strip() or DEFAULT_SECRET_ENV
    if re.fullmatch(r"[A-Z][A-Z0-9_]{0,63}", name) is None:
        raise PlaceError("Ogiltigt secret-namn. Ingen fil skrevs.")
    return name


def payload_bytes(secret: str) -> bytes:
    text = secret if secret.endswith("\n") else secret + "\n"
    return text.encode("utf-8")


def enter(ftp: object, path: str) -> None:
    candidates = [path]
    stripped = path.lstrip("/")
    if stripped and stripped not in candidates:
        candidates.append(stripped)
    last: Exception | None = None
    for candidate in candidates:
        try:
            try:
                ftp.cwd("/")
            except FTP_ERRORS:
                pass
            ftp.cwd(candidate)
            return
        except FTP_ERRORS as err:
            last = err
    raise PlaceError("Kunde inte öppna katalogen ovanför webbroten. Ingen fil skrevs.") from last


def ensure_privat(ftp: object, parent: str) -> None:
    enter(ftp, parent)
    here = ftp.pwd().rstrip("/")
    if posixpath.basename(here) == "public_html" or "public_html" in here.split("/"):
        raise PlaceError("Står i public_html. Ingen fil skrevs.")
    try:
        ftp.cwd("privat")
    except FTP_ERRORS:
        ftp.mkd("privat")
        ftp.cwd("privat")
    landed = ftp.pwd().rstrip("/")
    if posixpath.basename(landed) != "privat":
        raise PlaceError("Hamnade inte i privat/. Ingen fil skrevs.")
    if "public_html" in [part for part in landed.split("/") if part]:
        raise PlaceError("privat ligger under public_html. Ingen fil skrevs.")


def place(ftp: object, secret: str) -> int:
    data = payload_bytes(secret)
    dest = resolved_dest()
    ensure_privat(ftp, webroot_parent(os.environ.get("LOOPIA_FTP_ROOT", DEFAULT_ROOT)))
    ftp.storbinary(f"STOR {dest}", io.BytesIO(data), blocksize=8192)
    ftp.voidcmd(f"SITE CHMOD 600 {dest}")
    size = ftp.size(dest)
    if size is None or int(size) != len(data):
        raise PlaceError("Storleken efter uppladdning stämde inte.")
    print(f"skrev privat/{dest} bytes={int(size)} chmod=600", flush=True)
    return 0


def open_ftp():
    """Logga in med deploy-skriptets FTPS. Egna värden skickas inte."""
    host, user, password, port = read_loopia_ftp_env()
    if not host or not user or not password:
        raise PlaceError("FTP-secrets saknas. Ingen fil skrevs.")
    timeout = float(os.environ.get("JEAB_DEPLOY_TIMEOUT", "180"))
    try:
        return connect_with_credentials(
            host=host,
            user=user,
            password=password,
            port=port,
            timeout=timeout,
            insecure=False,
        )
    except DeployError as err:
        raise PlaceError("FTP-inloggning nekades. Ingen fil skrevs.") from err


def main() -> int:
    try:
        secret_env = resolved_secret_env()
    except PlaceError as err:
        print(redact(str(err)), file=sys.stderr, flush=True)
        return 1
    secret = os.environ.get(secret_env, "")
    host, user, password, _port = read_loopia_ftp_env()
    hidden = (secret, host, user, password, os.environ.get("LOOPIA_FTP_PASS", ""), os.environ.get("LOOPIA_FTP_USER", ""))
    if secret.strip() == "":
        print(redact(f"{secret_env} saknas. Ingen fil skrevs.", *hidden), flush=True)
        return 1
    ftp = None
    try:
        ftp = open_ftp()
        return place(ftp, secret)
    except PlaceError as err:
        print(redact(str(err), *hidden), file=sys.stderr, flush=True)
        return 1
    except Exception as err:
        print(
            redact(f"Kunde inte skriva nyckelfilen ({type(err).__name__}).", *hidden),
            file=sys.stderr,
            flush=True,
        )
        return 1
    finally:
        if ftp is not None:
            try:
                ftp.quit()
            except Exception:
                try:
                    ftp.close()
                except Exception:
                    pass


if __name__ == "__main__":
    raise SystemExit(main())
