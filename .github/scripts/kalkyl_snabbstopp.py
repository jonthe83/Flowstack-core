#!/usr/bin/env python3
"""Manuellt snabbstopp för två kalkylträd på Loopia. check eller apply.

check (standard) skriver bara de fasta åtgärderna och ansluter inte.
apply loggar in med samma FTPS som kalkyl_pull.py (LOOPIA_FTP_HOST,
LOOPIA_FTP_USER, LOOPIA_FTP_PASS), kontrollerar den absoluta hemkatalogen
och utför sedan bara listan nedan.

Flytt är RNFR/RNTO. Kataloger som redan finns som mål hoppas över.
Inget i den här filen tar bort en fil eller en katalog.

Åtgärder, alla fasta:

* /kalkyl.jarfalla.example.test/public_html/lead
  → /kalkyl.jarfalla.example.test/arkiv-2026-10-19/lead
* /kalkyl.jarfalla.example.test/public_html/find
  → /kalkyl.jarfalla.example.test/arkiv-2026-10-19/find
* /api.kalkyl.jarfalla.example.test/public_html/.idea
  → /api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/.idea
* /api.kalkyl.jarfalla.example.test/public_html/adminer.php
  → /api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/adminer.php
* /api.kalkyl.jarfalla.example.test/public_html/un.php
  → /api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/un.php
* /api.kalkyl.jarfalla.example.test/public_html/vendor.zip
  → /api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/vendor.zip
* Basic Auth på /api.kalkyl.jarfalla.example.test/public_html/dashboard/.htaccess
* Basic Auth enbart för GET och DELETE mot /search-logs i api-rotens .htaccess.
  POST /search-logs och POST /contact lämnas utan Basic Auth.
* Bcrypt-hash av GitHub-secretet KALKYL_BASIC_AUTH (user:password) till
  /api.kalkyl.jarfalla.example.test/private/.htpasswd

Rör aldrig /kalkyl.jarfalla.example.test/public_html/rot/, kalkylernas
src-kataloger eller /flowstack.example.test/public_html.

Rollback: flytta tillbaka med RNFR/RNTO, i omvänd riktning. Ingen radering.
Återställ de två .htaccess-filerna från arkiv-2026-10-19/
htaccess-api-root.original och htaccess-dashboard.original.
Lösenordsfilen skrivs över vid en ny apply. Det här skriptet tar inte bort den.
"""

from __future__ import annotations

import argparse
import os
import posixpath
import re
import ssl
import sys
import urllib.error
import urllib.request

_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
if _SCRIPT_DIR not in sys.path:
    sys.path.insert(0, _SCRIPT_DIR)

from loopia_deploy import (  # noqa: E402
    FTP_ERRORS,
    SessionFTP,
    close_ftp,
    restore_binary,
    retr_bytes,
    stor_bytes,
)

MARKER_BEGIN = "# JEAB-KALKYL-SNABBSTOPP-BEGIN"
MARKER_END = "# JEAB-KALKYL-SNABBSTOPP-END"

SITE_KALKYL = "/kalkyl.jarfalla.example.test"
SITE_API = "/api.kalkyl.jarfalla.example.test"
KALKYL_PUBLIC = SITE_KALKYL + "/public_html"
KALKYL_ARCHIVE = SITE_KALKYL + "/arkiv-2026-10-19"
API_PUBLIC = SITE_API + "/public_html"
API_ARCHIVE = SITE_API + "/arkiv-2026-10-19"
API_PRIVATE = SITE_API + "/private"
DASHBOARD_DIR = API_PUBLIC + "/dashboard"

ARCHIVE_DIRS = (KALKYL_ARCHIVE, API_ARCHIVE)
MOVES = (
    (KALKYL_PUBLIC + "/lead", KALKYL_ARCHIVE + "/lead"),
    (KALKYL_PUBLIC + "/find", KALKYL_ARCHIVE + "/find"),
    (API_PUBLIC + "/.idea", API_ARCHIVE + "/.idea"),
    (API_PUBLIC + "/adminer.php", API_ARCHIVE + "/adminer.php"),
    (API_PUBLIC + "/un.php", API_ARCHIVE + "/un.php"),
    (API_PUBLIC + "/vendor.zip", API_ARCHIVE + "/vendor.zip"),
)
ROOT_HTACCESS = API_PUBLIC + "/.htaccess"
DASHBOARD_HTACCESS = DASHBOARD_DIR + "/.htaccess"
BACKUP_ROOT = API_ARCHIVE + "/htaccess-api-root.original"
BACKUP_DASHBOARD = API_ARCHIVE + "/htaccess-dashboard.original"
HTPASSWD = API_PRIVATE + "/.htpasswd"

# Dokumenterade gränser. De ingår inte i ALLOWED och nås inte av apply.
NEVER_TOUCH = (
    "/kalkyl.jarfalla.example.test/public_html/rot",
    "/kalkyl.jarfalla.example.test/public_html/src",
    "/kalkyl.malmo.example.test/public_html/src",
    "/api.kalkyl.jarfalla.example.test/public_html/src",
    "/flowstack.example.test/public_html",
)
NEVER_FRAGMENTS = ("/rot", "/src", "flowstack.example.test", "malmo-demo")

ALLOWED = frozenset(
    {
        SITE_KALKYL,
        SITE_API,
        KALKYL_PUBLIC,
        KALKYL_ARCHIVE,
        API_PUBLIC,
        API_ARCHIVE,
        API_PRIVATE,
        DASHBOARD_DIR,
        ROOT_HTACCESS,
        DASHBOARD_HTACCESS,
        BACKUP_ROOT,
        BACKUP_DASHBOARD,
        HTPASSWD,
        *(path for pair in MOVES for path in pair),
    }
)

# Verb som anslutningen får skicka. Allt annat stoppas.
VERBS = frozenset(
    {
        "ABOR",
        "AUTH",
        "CDUP",
        "CWD",
        "EPRT",
        "EPSV",
        "FEAT",
        "LIST",
        "MDTM",
        "MKD",
        "MLSD",
        "MLST",
        "MODE",
        "NLST",
        "NOOP",
        "OPTS",
        "PASS",
        "PASV",
        "PBSZ",
        "PORT",
        "PROT",
        "PWD",
        "QUIT",
        "REST",
        "RETR",
        "RNFR",
        "RNTO",
        "SIZE",
        "STAT",
        "STOR",
        "STRU",
        "SYST",
        "TYPE",
        "USER",
        "XCWD",
        "XPWD",
    }
)

_HOME_OK = re.compile(r"^/(?:home|www|customers|usr|var/www)(?:/[A-Za-z0-9._-]+)+$")
_QUOTED_PATH = re.compile(r'"(/[^"]+)"')
_USER_OK = re.compile(r"[A-Za-z0-9._-]{1,64}")

# method, url, förväntan, extra headers. Förväntan är ett fast ord, inte indata.
HTTP_CHECKS = (
    ("GET", "https://kalkyl.jarfalla.example.test/lead/", "404", ()),
    ("GET", "https://kalkyl.jarfalla.example.test/find/", "404", ()),
    ("GET", "https://api.kalkyl.jarfalla.example.test/.idea/workspace.xml", "404", ()),
    ("GET", "https://api.kalkyl.jarfalla.example.test/adminer.php", "404", ()),
    ("GET", "https://api.kalkyl.jarfalla.example.test/dashboard/", "401", ()),
    (
        "GET",
        "https://api.kalkyl.jarfalla.example.test/search-logs",
        "401",
        (("X-API-Version", "1"),),
    ),
    ("POST", "https://api.kalkyl.jarfalla.example.test/search-logs", "not-401", ()),
    ("GET", "https://kalkyl.jarfalla.example.test/rot/action_page.php", "not-401-404", ()),
    ("GET", "https://kalkyl.jarfalla.example.test/", "200", ()),
    ("GET", "https://kalkyl.malmo.example.test/", "200", ()),
)

_secrets: list[str] = []


class StopError(RuntimeError):
    """Åtgärden kan inte fortsätta."""


class GuardedFTP(SessionFTP):
    """Samma TLS-session som deploy-skriptet. Bara verb i VERBS."""

    def sendcmd(self, cmd: str) -> str:
        verb = cmd.split(None, 1)[0].upper() if cmd.strip() else ""
        assert_verb(verb)
        return super().sendcmd(cmd)


def assert_verb(verb: str) -> None:
    if verb not in VERBS:
        raise StopError(f"vägrat FTP-kommando: {verb}")


def register_secret(value: str) -> None:
    if value and value not in _secrets:
        _secrets.append(value)


def redact(text: str) -> str:
    for secret in sorted(_secrets, key=len, reverse=True):
        if secret:
            text = text.replace(secret, "***")
    return text


def guard_path(path: str) -> str:
    """Tillåt bara de fasta sökvägarna. Avvisar .., rot, src och JEAB."""
    if not path or "\\" in path or "\x00" in path:
        raise StopError("otillåten sökväg")
    normal = posixpath.normpath(path)
    if normal != path.rstrip("/"):
        raise StopError("otillåten sökväg")
    if normal not in ALLOWED:
        raise StopError("otillåten sökväg")
    for fragment in NEVER_FRAGMENTS:
        if fragment in normal:
            raise StopError("otillåten sökväg")
    return normal


def _audit_constants() -> None:
    for path in ALLOWED:
        for banned in NEVER_TOUCH:
            if path == banned or path.startswith(banned + "/"):
                raise RuntimeError("en fast sökväg träffar en förbjuden gräns")
        for fragment in NEVER_FRAGMENTS:
            if fragment in path:
                raise RuntimeError("en fast sökväg träffar en förbjuden gräns")


_audit_constants()


def rollback_lines() -> list[str]:
    """Flytta tillbaka arkivet. Samma par som MOVES, omvänd riktning."""
    lines = ["Rollback: flytta tillbaka med RNFR/RNTO. Ingen radering."]
    for src, dst in MOVES:
        lines.append(f"RNFR {dst}")
        lines.append(f"RNTO {src}")
    lines.append(f"Återställ {ROOT_HTACCESS} från {BACKUP_ROOT}.")
    lines.append(
        f"Återställ {DASHBOARD_HTACCESS} från {BACKUP_DASHBOARD}. "
        "Saknas backupen fanns ingen fil; ta då bort markörblocket för hand."
    )
    lines.append(
        f"{HTPASSWD} skrivs över vid apply. Skriptet tar inte bort den. "
        "Byt den genom att köra apply igen."
    )
    return lines


def plan_lines() -> list[str]:
    lines = ["mode=check", "Åtgärderna är fasta. Ingen anslutning."]
    for path in ARCHIVE_DIRS:
        lines.append(f"MKD {path}")
    lines.append(f"MKD {API_PRIVATE}")
    for src, dst in MOVES:
        lines.append(f"MOVE {src} {dst}")
    lines.append(f"BACKUP {BACKUP_ROOT}")
    lines.append(f"MERGE {ROOT_HTACCESS}")
    lines.append(f"BACKUP {BACKUP_DASHBOARD}")
    lines.append(f"STOR {DASHBOARD_HTACCESS}")
    lines.append("AuthUserFile <absolut hem>/api.kalkyl.jarfalla.example.test/private/.htpasswd")
    lines.append(f"STOR {HTPASSWD}")
    lines.append("HTTP efter apply, bara statuskod:")
    for method, url, kind, _headers in HTTP_CHECKS:
        lines.append(f"HTTP {method} {url} {kind}")
    lines.extend(rollback_lines())
    return lines


def root_block(auth_user_file: str) -> str:
    if not _auth_file_ok(auth_user_file):
        raise StopError("AuthUserFile är ogiltig. Inget ändrades.")
    return (
        f"{MARKER_BEGIN}\n"
        "# Basic Auth enbart för GET och DELETE mot /search-logs.\n"
        "# POST /search-logs och POST /contact lämnas utan Basic Auth.\n"
        '<If "%{THE_REQUEST} =~ m#^(GET|DELETE)\\s+/search-logs/?(\\?|\\s|$)#">\n'
        "AuthType Basic\n"
        'AuthName "Kalkyl"\n'
        f"AuthUserFile {auth_user_file}\n"
        "Require valid-user\n"
        "</If>\n"
        f"{MARKER_END}\n"
    )


def dashboard_htaccess(auth_user_file: str) -> bytes:
    if not _auth_file_ok(auth_user_file):
        raise StopError("AuthUserFile är ogiltig. Inget ändrades.")
    text = (
        f"{MARKER_BEGIN}\n"
        "AuthType Basic\n"
        'AuthName "Kalkyl"\n'
        f"AuthUserFile {auth_user_file}\n"
        "Require valid-user\n"
        f"{MARKER_END}\n"
    )
    return text.encode("ascii")


def merge_root_htaccess(original: bytes, auth_user_file: str) -> bytes:
    """Behåll varje befintlig rad. Lägg blocket sist, en gång."""
    text = original.decode("latin-1")
    if MARKER_BEGIN in text:
        return original
    block = root_block(auth_user_file)
    if text and not text.endswith("\n"):
        text += "\n"
    if text:
        text += "\n"
    text += block
    return text.encode("latin-1")


def _auth_file_ok(path: str) -> bool:
    if not path.startswith("/") or ".." in path.split("/"):
        return False
    if any(char.isspace() for char in path):
        return False
    return path.endswith("/api.kalkyl.jarfalla.example.test/private/.htpasswd")


def auth_user_file(home: str) -> str:
    home = home.rstrip("/")
    if not _HOME_OK.match(home):
        raise StopError("serverns absoluta hemkatalog är okänd. Inget ändrades.")
    path = posixpath.join(home, "api.kalkyl.jarfalla.example.test/private/.htpasswd")
    if not _auth_file_ok(path):
        raise StopError("AuthUserFile är ogiltig. Inget ändrades.")
    return path


def expect_ok(kind: str, status: int) -> bool:
    if kind == "404":
        return status == 404
    if kind == "401":
        return status == 401
    if kind == "200":
        return status == 200
    if kind == "not-401":
        return status != 401
    if kind == "not-401-404":
        return status not in {401, 404}
    raise StopError("okänd HTTP-kontroll")


def split_basic_auth(raw: str) -> tuple[str, str]:
    if raw.strip() == "":
        raise StopError(
            "KALKYL_BASIC_AUTH saknas. Sätt GitHub-secretet till user:password. Inget utfördes."
        )
    if "\n" in raw or "\r" in raw or "\x00" in raw:
        raise StopError("KALKYL_BASIC_AUTH har ett radbryt. Inget utfördes.")
    if ":" not in raw:
        raise StopError("KALKYL_BASIC_AUTH ska vara user:password. Inget utfördes.")
    user, password = raw.split(":", 1)
    if not _USER_OK.fullmatch(user) or password == "":
        raise StopError("KALKYL_BASIC_AUTH ska vara user:password. Inget utfördes.")
    register_secret(raw)
    register_secret(password)
    return user, password


def hash_password(password: str) -> str:
    """Bcrypt via libc. Apache får prefixet $2y$."""
    import crypt

    if not hasattr(crypt, "METHOD_BLOWFISH"):
        raise StopError("bcrypt finns inte i den här Python. Inget laddades upp.")
    try:
        hashed = crypt.crypt(password, crypt.mksalt(crypt.METHOD_BLOWFISH))
    except Exception as err:
        raise StopError("bcrypt misslyckades. Inget laddades upp.") from err
    if not hashed or not hashed.startswith("$2"):
        raise StopError("bcrypt misslyckades. Inget laddades upp.")
    if hashed.startswith("$2b$"):
        hashed = "$2y$" + hashed[4:]
    if not hashed.startswith("$2y$") or password in hashed:
        raise StopError("bcrypt misslyckades. Inget laddades upp.")
    return hashed


def build_htpasswd(raw: str) -> bytes:
    user, password = split_basic_auth(raw)
    hashed = hash_password(password)
    line = f"{user}:{hashed}\n"
    if password in line or raw in line:
        raise StopError("lösenordsfilen innehåller hemligheten. Inget laddades upp.")
    try:
        return line.encode("ascii")
    except UnicodeEncodeError as err:
        raise StopError("lösenordsfilen är inte ASCII. Inget laddades upp.") from err


def missing_ftp_secrets() -> list[str]:
    missing = []
    for name in ("LOOPIA_FTP_HOST", "LOOPIA_FTP_USER", "LOOPIA_FTP_PASS"):
        if not os.environ.get(name, "").strip():
            missing.append(name)
    return missing


def connect() -> GuardedFTP:
    host = os.environ.get("LOOPIA_FTP_HOST", "").strip()
    user = os.environ.get("LOOPIA_FTP_USER", "").strip()
    password = os.environ.get("LOOPIA_FTP_PASS", "")
    register_secret(user)
    register_secret(password.strip())
    missing = missing_ftp_secrets()
    if missing:
        raise StopError("saknar FTP-secrets: " + ", ".join(missing) + ". Ingen anslutning gjordes.")
    port = int(os.environ.get("LOOPIA_FTP_PORT", "21"))
    timeout = float(os.environ.get("JEAB_DEPLOY_TIMEOUT", "180"))
    context = ssl.create_default_context()
    ftp = GuardedFTP(context=context)
    ftp.connect(host, port, timeout=timeout)
    try:
        ftp.login(user, password)
    except FTP_ERRORS as err:
        try:
            ftp.close()
        except Exception:
            pass
        raise StopError("FTP-inloggning nekades") from err
    ftp.prot_p()
    ftp.set_pasv(True)
    ftp.voidcmd("TYPE I")
    ftp.encoding = "utf-8"
    if ftp.sock is not None:
        ftp.sock.settimeout(timeout)
    return ftp


def check_home(ftp) -> str:
    """Läs PWD och lista inloggningskatalogen. Skriv inget.

    Hemkatalogen måste vara en absolut sökväg under home, www, customers,
    usr eller var/www, och den måste innehålla kalkylträden. Annars avbryts
    apply innan någon flytt eller uppladdning.
    """
    here = ftp.pwd().rstrip("/") or "/"
    raw = ""
    try:
        raw = ftp.sendcmd("PWD")
    except FTP_ERRORS:
        raw = ""
    quoted = [item.rstrip("/") for item in _QUOTED_PATH.findall(raw)]
    candidate = here
    if quoted and quoted[0] == here:
        candidate = quoted[0]
    if not _HOME_OK.match(candidate):
        raise StopError("serverns absoluta hemkatalog är okänd. AuthUserFile kan inte sättas. Inget ändrades.")
    for fragment in NEVER_FRAGMENTS:
        if fragment in candidate:
            raise StopError("serverns absoluta hemkatalog är okänd. Inget ändrades.")
    try:
        names = _list_basenames(ftp)
    except FTP_ERRORS as err:
        raise StopError("kan inte lista inloggningskatalogen. Inget ändrades.") from err
    if "api.kalkyl.jarfalla.example.test" not in names or "kalkyl.jarfalla.example.test" not in names:
        raise StopError(
            "serverns absoluta hemkatalog är okänd. "
            "Inloggningskatalogen innehåller inte kalkylträden. Inget ändrades."
        )
    return candidate


def _list_basenames(ftp) -> list[str]:
    try:
        raw = ftp.nlst()
    finally:
        restore_binary(ftp)
    names: list[str] = []
    for item in raw:
        base = posixpath.basename(str(item).rstrip("/"))
        if base and base not in {".", ".."}:
            names.append(base)
    return names


class Session:
    """CWD, RNFR/RNTO, MKD och STOR bara mot ALLOWED, plus / och hem."""

    def __init__(self, ftp, origin: str) -> None:
        self.ftp = ftp
        origin = origin.rstrip("/") or "/"
        self.origin = origin
        self.cwd_ok = set(ALLOWED)
        self.cwd_ok.add("/")
        if origin != "/":
            if not _HOME_OK.match(origin):
                raise StopError("serverns absoluta hemkatalog är okänd. Inget ändrades.")
            self.cwd_ok.add(origin)

    def log(self, text: str) -> None:
        print(redact(text), flush=True)

    def cwd(self, path: str) -> None:
        target = path.rstrip("/") or "/"
        if target not in self.cwd_ok:
            raise StopError("otillåten katalog")
        assert_verb("CWD")
        self.ftp.cwd(target)

    def command(self, cmd: str) -> str:
        verb, _, arg = cmd.partition(" ")
        assert_verb(verb.upper())
        if verb.upper() in {"RNFR", "RNTO"}:
            guard_path(arg.strip())
        return self.ftp.sendcmd(cmd)

    def exists(self, path: str) -> bool:
        guard_path(path)
        saved = self.ftp.pwd()
        try:
            try:
                self.cwd(path)
                return True
            except FTP_ERRORS:
                pass
            parent, name = posixpath.split(path)
            try:
                self.cwd(parent)
            except FTP_ERRORS:
                return False
            assert_verb("TYPE")
            restore_binary(self.ftp)
            try:
                self.ftp.size(name)
                return True
            except FTP_ERRORS:
                pass
            return name in _list_basenames(self.ftp)
        finally:
            self.cwd(saved if (saved.rstrip("/") or "/") in self.cwd_ok else "/")

    def ensure_dir(self, path: str) -> None:
        guard_path(path)
        if self.exists(path):
            self.log(f"finns {path}")
            return
        parent, name = posixpath.split(path)
        self.cwd(parent)
        assert_verb("MKD")
        self.ftp.mkd(name)
        self.log(f"MKD {path}")

    def move(self, src: str, dst: str) -> None:
        guard_path(src)
        guard_path(dst)
        if self.exists(dst):
            self.log(f"hoppar över, målet finns {dst}")
            return
        if not self.exists(src):
            self.log(f"hoppar över, källan saknas {src}")
            return
        self.cwd("/")
        self.command(f"RNFR {src}")
        self.command(f"RNTO {dst}")
        self.log(f"MOVE {src} {dst}")

    def read_file(self, path: str) -> bytes | None:
        guard_path(path)
        if not self.exists(path):
            return None
        parent, name = posixpath.split(path)
        self.cwd(parent)
        assert_verb("RETR")
        return retr_bytes(self.ftp, name, 8192)

    def write_file(self, path: str, data: bytes) -> None:
        guard_path(path)
        parent, name = posixpath.split(path)
        self.cwd(parent)
        assert_verb("STOR")
        stor_bytes(self.ftp, name, data, 8192)
        self.log(f"STOR {path} bytes={len(data)}")


def execute(ftp, home: str, payload: bytes) -> None:
    """Flytta, slå ihop .htaccess och ladda upp lösenordsfilen."""
    auth_file = auth_user_file(home)
    session = Session(ftp, home)
    for path in ARCHIVE_DIRS:
        session.ensure_dir(path)
    session.ensure_dir(API_PRIVATE)
    for src, dst in MOVES:
        session.move(src, dst)
    _update_root(session, auth_file)
    _update_dashboard(session, auth_file)
    session.write_file(HTPASSWD, payload)


def _update_root(session: Session, auth_file: str) -> None:
    original = session.read_file(ROOT_HTACCESS)
    if original is None:
        original = b""
        session.log(f"saknas {ROOT_HTACCESS}")
    merged = merge_root_htaccess(original, auth_file)
    if MARKER_BEGIN.encode("ascii") in original:
        session.log(f"redan satt {ROOT_HTACCESS}")
        return
    if session.exists(BACKUP_ROOT):
        session.log(f"hoppar över, målet finns {BACKUP_ROOT}")
    else:
        session.write_file(BACKUP_ROOT, original)
    session.write_file(ROOT_HTACCESS, merged)


def _update_dashboard(session: Session, auth_file: str) -> None:
    desired = dashboard_htaccess(auth_file)
    current = session.read_file(DASHBOARD_HTACCESS)
    if current == desired:
        session.log(f"redan satt {DASHBOARD_HTACCESS}")
        return
    if current is not None:
        if session.exists(BACKUP_DASHBOARD):
            session.log(f"hoppar över, målet finns {BACKUP_DASHBOARD}")
        else:
            session.write_file(BACKUP_DASHBOARD, current)
    else:
        session.log(f"saknas {DASHBOARD_HTACCESS}")
    session.write_file(DASHBOARD_HTACCESS, desired)


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def fetch_status(method: str, url: str, headers: tuple[tuple[str, str], ...]) -> int:
    """Hämta bara statuskoden. Läs inte svarskroppen."""
    data = b"" if method == "POST" else None
    request = urllib.request.Request(
        url,
        data=data,
        method=method,
        headers={
            "User-Agent": "JEAB-kalkyl-snabbstopp/1",
            "Cache-Control": "no-cache",
            **dict(headers),
        },
    )
    opener = urllib.request.build_opener(_NoRedirect)
    try:
        with opener.open(request, timeout=30) as response:
            return int(response.status)
    except urllib.error.HTTPError as err:
        return int(err.code)


def verify_http(fetch=fetch_status) -> None:
    failed: list[str] = []
    for method, url, kind, headers in HTTP_CHECKS:
        status = int(fetch(method, url, headers))
        print(status, flush=True)
        if not expect_ok(kind, status):
            failed.append(f"{status} {method} {url}")
    if failed:
        raise StopError("HTTP-kontrollen föll: " + ", ".join(failed))


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="kalkyl_snabbstopp.py",
        description="Kalkyl-snabbstopp. check listar, apply utför.",
    )
    parser.add_argument("--mode", choices=("check", "apply"), default="check")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(sys.argv[1:] if argv is None else argv)
    if args.mode == "check":
        for line in plan_lines():
            print(line, flush=True)
        return 0
    raw = os.environ.get("KALKYL_BASIC_AUTH", "")
    register_secret(raw)
    register_secret(os.environ.get("LOOPIA_FTP_PASS", ""))
    payload = build_htpasswd(raw)
    if missing_ftp_secrets():
        names = ", ".join(missing_ftp_secrets())
        raise StopError(f"saknar FTP-secrets: {names}. Ingen anslutning gjordes.")
    ftp = None
    try:
        ftp = connect()
        home = check_home(ftp)
        print(f"hem {home}", flush=True)
        execute(ftp, home, payload)
        verify_http()
        return 0
    finally:
        close_ftp(ftp)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except StopError as err:
        print(redact(str(err)), file=sys.stderr)
        sys.exit(1)
    except Exception as err:
        print(redact(f"avbruten: {type(err).__name__}: {err}"), file=sys.stderr)
        sys.exit(1)
