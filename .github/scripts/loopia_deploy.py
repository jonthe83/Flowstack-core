#!/usr/bin/env python3
"""Deploy en fillista från git till Loopia. Transporten är utbytbar.

``auto`` (standard) gör en kort FTP-kontroll (inloggning och PWD). Lyckas den
används FTPES. Misslyckas den växlar körningen till TinyFM utan att ha rört en
live-fil. ``ftp`` stoppar om kontrollen faller. ``tinyfm`` hoppar över FTP.

Underhåll först. Innan någon appfil skrivs laddas ``private/deploying.flag``
upp med unix-tid. Ingångarna svarar då 503. Flaggan töms efter uppladdningen
genom att innehållet skrivs över med tomt — den döps inte om och raderas inte.
En tom flagga är av. Är tidsstämpeln äldre än 3 minuter ignorerar appen den.
``private/.htaccess`` (och ``private/backups/.htaccess`` om den finns i
committen) tas med om servern saknar filen eller om spärren är ofullständig.
En full spärr har ``Require all denied``, ``Order deny,allow``,
``Deny from all`` och ``Options -Indexes``. ``RewriteEngine On`` utan en
``[F]``-regel räknas inte: den slår ut rotens ``RewriteRule ^private/``.

Sedan ``src/`` (beroenden först inom katalogen) och ingångsfilerna sist
(``index.php``, ``hook.php``, ``graph-hook.php``, ``webshop-hook.php``,
``api/omtago-lead.php`` och övriga omtago-/kalkyl-ingångar utanför ``src/``).
Live-filer byts som tidigare via tmp. Ingen katalog byts ut. Flaggan själv
skrivs på plats, utan rename och utan delete.

Per fil, en i taget:

1. Ladda upp till ``<namn>.deploy-<kortsha>.tmp``. Aldrig skriv på live-namnet.
2. Ladda ner tmp och jämför storlek och sha256. Vid fel: FTP försöker igen.
   TinyFM gör inget omförsök. Annars radera tmp och rör inte live.
3. Om live finns: en kvarlämnad ``<namn>.bak`` eller ``<namn>.bak*`` från en
   tidigare körning flyttas till
   ``data/.private/trash/<YYYY-MM-DD>/<samma relativa sökväg>`` (kataloger
   skapas). Live döps sedan om till ``<namn>.bak`` och direkt därefter tmp
   till live. Saknas live skapas filen utan att en eventuell backup flyttas.
   En fil som en annan fil i samma batch ``require``:ar byts först.
   Alfabetisk ordning lade ``src/bootstrap.php`` före nya
   ``src/test_lead.php``, och utloggad ``index.php`` dog med HTTP 500 tills
   filen fanns.
4. Verifiera live-filens sha256 via samma transport. Steget hoppas inte över.

Efter hela listan töms flaggan. Sedan två HTTP-anrop:

- GET mot grind-URL:en. 200 med «Ange kampanjkod» krävs.
- POST ``{}`` mot ``/hook.php`` på samma värd, User-Agent ``curl/8.5``.
  Kräver 401 och JSON ``ok`` = false.

Båda måste stämma för grönt. ``.bak`` och ``.bak*`` flyttas till
  ``data/.private/trash/<YYYY-MM-DD>/`` (FTP: RNFR/RNTO, TinyFM: verifierad
  kopia och sedan radering av källan, eftersom TinyFM inte döper om mellan
  kataloger). Inget skrivs över i papperskorgen. Filerna ligger kvar i två
  veckor. Skriptet raderar dem inte senare. En flytt 2026-10-30 hör hemma
  till städning 2026-11-13. ``*.uploading`` /
  ``.*.uploading`` och smoke-filer (``deploy-check.txt``, ``smoke``,
  ``smoke.txt``) raderas som tidigare. ``--keep-smoke`` behåller smoke-filerna.
- 403 med Loopia «Blocked Page»: OKLART. Ingen rollback. .bak lämnas kvar.
  Ingen städning av andras filer.
- 5xx, eller 200 utan grindtexten: återställ .bak till live och låt jobbet falla.
  Ingen städning av andras filer. Den .bak som bytet skapade används till
  återställningen och raderas då, som tidigare.

TinyFM använder en inloggningssession per körning och väntar mellan anropen.
Ett 403 med «Blocked Page» från TinyFM stoppar direkt, utan retry och utan
ytterligare anrop.

``--mode check`` läser live-filer och jämför sha256 med committen.
``--mode rollback`` lägger tillbaka ``.bak`` för angivna filer.
``--mode cleanup-bak`` raderar ``.bak`` och ``.bak*`` för ``--paths`` och
avslutar. Det läget är operatörens uttryckliga radering och flyttar inte
till papperskorgen. Ingen live-fil byts och grinden anropas inte.

``--mode list-root`` med transport ``ftp`` eller ``auto`` loggar in med
``LOOPIA_FTP_*``, kör PWD och listar bara katalognamn: uppåt med ``CWD ..``
så långt servern tillåter, sedan högst tre nivåer ned från den högsta nåbara
katalogen. Högst 200 poster per nivå. Inget skrivs, raderas, döps om, laddas
upp eller laddas ner. Saknade FTP-secrets avbryter utan anslutning.

``--mode service-redirect`` med transport ``ftp`` eller ``auto`` använder
samma ``LOOPIA_FTP_*``. ``tinyfm`` avvisas. Läget går till FTP-roten ``/``
på samma sätt som list-root och skapar bara
``/service.jarfalla.example.test`` och
``/service.jarfalla.example.test/public_html`` om de saknas. Saknas
``.htaccess`` laddas den hårdkodade filen
``deploy/service-redirect.htaccess`` upp. Finns den redan jämförs innehållet
med hash från servern: identiskt innehåll lämnas, annat innehåll avbryter
utan överskrivning. Torrkörning ansluter inte. Sökvägarna är konstanter.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
import posixpath
import re
import ssl
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from ftplib import FTP, FTP_TLS, all_errors as FTP_ERRORS

_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
if _SCRIPT_DIR not in sys.path:
    sys.path.insert(0, _SCRIPT_DIR)
from tinyfm_client import TinyFmClient, TinyFmError, WafBlocked

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DEFAULT_GATE = "https://flowstack.example.test/index.php"
DEFAULT_NEEDLE = "Ange kampanjkod"
DEFAULT_ROOT = "/flowstack.example.test/public_html"
DEPLOY_FLAG_DIR = "private"
DEPLOY_FLAG_NAME = "deploying.flag"
DEPLOY_FLAG_REL = DEPLOY_FLAG_DIR + "/" + DEPLOY_FLAG_NAME
PRIVATE_HTACCESS_FILES = (
    DEPLOY_FLAG_DIR + "/.htaccess",
    DEPLOY_FLAG_DIR + "/backups/.htaccess",
)
PRIVATE_HTACCESS = PRIVATE_HTACCESS_FILES[0]
# Spärrfilerna måste gå att ladda upp även när prefixet private/ är webbnekad.
PRIVATE_HTACCESS_ALLOW = frozenset(PRIVATE_HTACCESS_FILES)
HOOK_UA = "curl/8.5"
_ENTRY_EXACT = frozenset(
    {
        "index.php",
        "hook.php",
        "graph-hook.php",
        "webshop-hook.php",
        "api/omtago-lead.php",
    }
)
def load_web_deny_spec(path: str | None = None) -> tuple[tuple[str, ...], tuple[str, ...]]:
    """Shared with src/shield.php via deploy/sensitive-paths.txt."""
    prefixes: list[str] = []
    exacts: list[str] = []
    file = path or os.path.join(ROOT, "deploy", "sensitive-paths.txt")
    try:
        with open(file, encoding="utf-8") as handle:
            for raw in handle:
                line = raw.split("#", 1)[0].strip()
                if not line:
                    continue
                kind, _, value = line.partition(" ")
                value = value.strip().strip("/")
                if kind == "prefix" and value:
                    prefixes.append(value + "/")
                elif kind == "exact" and value:
                    exacts.append(value)
    except OSError:
        prefixes = ["data/", "schema/", "src/", "tests/", "fullmakt/data/", "fullmakt/lib/", "fullmakt/vendor/", "gi/data/"]
        exacts = ["README.md", "AGENTS.md"]
    return tuple(prefixes), tuple(exacts)


WEB_DENY_PREFIXES, WEB_DENY_EXACT = load_web_deny_spec()
# These prefixes are web-denied (direct hits and missing files) but the app must still upload.
# fullmakt/data/ is not in this tuple: deploy and the shield both refuse it.
DEPLOY_UPLOAD_ANYWAY = ("src/", "fullmakt/lib/", "fullmakt/vendor/")
DENY_PREFIXES = (
    ".github/",
    ".git/",
    ".cursor/",
) + tuple(prefix for prefix in WEB_DENY_PREFIXES if prefix not in DEPLOY_UPLOAD_ANYWAY)
DENY_BASENAMES = {
    "filer.php",
    "passwords.json",
    "passwords.example.json",
    "config.php",
    "tokens.json",
    "fortnox_tokens.json",
    "fortnox_config.php",
    "oauth_setup.php",
}
SECRET_SUFFIXES = (".pem", ".key", ".p12", ".pfx")
SMOKE_BASENAMES = frozenset(
    {
        "deploy-check.txt",
        "deploy-check",
        "smoke",
        "smoke.txt",
        "smoke.php",
    }
)
_BAK_SUFFIX = re.compile(r"\.bak(?:\d+|[-._][\w.\-]*)?$")
_TRASH_ROOT = "data/.private/trash"
_TRASH_DAY = re.compile(r"^\d{4}-\d{2}-\d{2}$")
# filer.php kräver de här innan TinyFM hinner svara. Döp inte bort dem:
# nästa anrop blir HTTP 500 och bytet kan inte slutföras.
_BOOT_ALWAYS = frozenset(
    {
        "src/filer_boot.php",
        "src/shield.php",
        "src/tinyfm_allow.php",
        "src/bootstrap.php",
        "src/layout.php",
        "src/ordrar.php",
    }
)

_secrets: list[str] = []


class DeployError(Exception):
    """Fel som ska fälla jobbet. Live-filen är återställd eller har inte rörts."""


class MissingFile(DeployError):
    """Filen finns inte på servern."""


class VerifyError(DeployError):
    """Tmp-filen stämde inte. Säkert att försöka uppladdningen igen."""


class GateError(DeployError):
    """HTTP-kontrollen föll efter att live-filen bytts ut."""


class SessionFTP(FTP_TLS):
    """Explicit FTPS som återanvänder TLS-sessionen på datakanalen.

    Utan det bryter ProFTPD och Pure-FTPd ofta överföringen efter ``PROT P``.
    """

    def ntransfercmd(self, cmd: str, rest: str | None = None) -> tuple:
        conn, size = FTP.ntransfercmd(self, cmd, rest)
        if self._prot_p:
            conn = self.context.wrap_socket(
                conn,
                server_hostname=self.host,
                session=self.sock.session,
            )
        return conn, size


@dataclass
class Item:
    action: str
    path: str
    reason: str = ""
    old_path: str | None = None


@dataclass
class Result:
    path: str
    size: int
    sha: str
    outcome: str


@dataclass
class Swapped:
    path: str
    rel_dir: str
    base: str
    tmp: str
    bak: str
    bad: str
    mode: str
    previous: bytes | None


@dataclass
class Config:
    repo: str
    commit: str
    base: str
    paths: list[str]
    dry_run: bool
    from_diff: bool
    insecure: bool
    require_main: bool
    allow_any_commit: bool
    host: str
    user: str
    password: str
    root: str
    port: int
    gate_url: str
    needle: str
    attempts: int
    delays: list[float]
    timeout: float
    http_timeout: float
    blocksize: int
    mode: str = "deploy"
    keep_smoke: bool = False
    maintenance: bool = False
    short: str = ""
    transport: str = "auto"
    ftp_probe_timeout: float = 8
    tinyfm_url: str = ""
    tinyfm_user: str = ""
    tinyfm_pass: str = ""
    campaign: str = ""
    gate_id: str = ""
    tinyfm_gap: float = 4
    since_sha: str = ""
    touched: list[tuple[str, str]] = field(default_factory=list)
    swapped: list[Swapped] = field(default_factory=list)


def reset_secrets() -> None:
    _secrets.clear()


def remember_secret(value: str) -> None:
    if value and len(value) >= 4 and value not in _secrets:
        _secrets.append(value)


def mask_value(value: str) -> None:
    """Dölj en cookie eller ett sessions-id i loggen.

    GitHub Actions läser ``::add-mask::``. Värdet skrivs inte ut på annat sätt.
    Radbrytning hoppas över så att ett svar inte kan bli ett workflow-kommando.
    """
    if not value or len(value) < 8 or any(char in value for char in "\r\n"):
        return
    remember_secret(value)
    if os.environ.get("GITHUB_ACTIONS") == "true":
        print(f"::add-mask::{value}", flush=True)


def redact(text: str) -> str:
    for secret in _secrets:
        if secret:
            text = text.replace(secret, "***")
    return text


def log(message: str) -> None:
    print(redact(message), flush=True)


def git(repo: str, *args: str, text: bool = True) -> str | bytes:
    proc = subprocess.run(
        ["git", "-C", repo, *args],
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if text:
        return proc.stdout.decode().strip()
    return proc.stdout


def git_ok(repo: str, *args: str) -> bool:
    proc = subprocess.run(
        ["git", "-C", repo, *args],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    return proc.returncode == 0


def normalize_rel(path: str) -> str:
    rel = path.replace("\\", "/").strip()
    while rel.startswith("./"):
        rel = rel[2:]
    return rel.lstrip("/")


def deny_reason(path: str) -> str | None:
    rel = normalize_rel(path)
    if not rel or ".." in rel.split("/"):
        return "ogiltig sökväg"
    if rel in PRIVATE_HTACCESS_ALLOW:
        return None
    base = posixpath.basename(rel)
    lower = base.lower()
    # Fortnox-token (JEAB + MEAB) ligger i data/.private/fortnox/tokens.json.
    # Den och gamla kopior (rot/fortnox_tokens.json, tokens.json) får aldrig
    # skrivas över av en deploy, även om någon pekar ut filen i --paths.
    if lower in {"fortnox_tokens.json", "tokens.json"} or rel.endswith("/fortnox/tokens.json"):
        return "fortnox-token"
    if rel in WEB_DENY_EXACT:
        return rel
    if any(rel == prefix.rstrip("/") or rel.startswith(prefix) for prefix in DEPLOY_UPLOAD_ANYWAY):
        pass
    elif any(rel == prefix.rstrip("/") or rel.startswith(prefix) for prefix in WEB_DENY_PREFIXES):
        matched = next(prefix for prefix in WEB_DENY_PREFIXES if rel == prefix.rstrip("/") or rel.startswith(prefix))
        return matched.rstrip("/")
    if lower.startswith("readme") or lower.endswith(".md"):
        return "dokumentation"
    for prefix in DENY_PREFIXES:
        if rel == prefix[:-1] or rel.startswith(prefix):
            return prefix.rstrip("/")
    if lower in DENY_BASENAMES or lower.startswith(".env"):
        return "hemlighet eller spärrad fil"
    if lower.endswith(SECRET_SUFFIXES) or "id_rsa" in lower:
        return "hemlighet"
    if "/secrets/" in f"/{rel}" or rel.startswith("secrets/"):
        return "hemlighet"
    if lower.endswith(".sqlite"):
        return "hemlighet"
    if lower.startswith(".") and lower != ".htaccess":
        if not (lower == ".user.ini" and rel.startswith("fullmakt/vendor/")):
            return "dold fil"
    return None


def safe_rel(path: str) -> str:
    rel = normalize_rel(path)
    if not rel or ".." in rel.split("/") or rel.startswith("/"):
        raise DeployError(f"ogiltig sökväg: {path}")
    return rel


def parse_name_status_z(raw: bytes) -> list[tuple[str, str, str | None]]:
    parts = raw.split(b"\0")
    if parts and parts[-1] == b"":
        parts.pop()
    changes: list[tuple[str, str, str | None]] = []
    index = 0
    while index < len(parts):
        status = parts[index].decode()
        index += 1
        code = status[:1]
        if code in {"R", "C"}:
            if index + 1 >= len(parts):
                break
            old = parts[index].decode()
            new = parts[index + 1].decode()
            index += 2
            changes.append((code, new, old))
            continue
        if index >= len(parts):
            break
        changes.append((code, parts[index].decode(), None))
        index += 1
    return changes


def split_paths(raw: str) -> list[str]:
    text = raw.replace(",", "\n")
    return [part.strip() for part in text.splitlines() if part.strip()]


def resolve_commit(repo: str, ref: str) -> str:
    try:
        return str(git(repo, "rev-parse", "--verify", f"{ref}^{{commit}}"))
    except subprocess.CalledProcessError as err:
        raise DeployError(f"okänd commit: {ref}") from err


EMPTY_TREE = "4b825dc642cb6eb9a060e54bf8d69288fbee4904"


def parent_commit(repo: str, commit: str) -> str:
    if git_ok(repo, "rev-parse", "--verify", "--quiet", f"{commit}^"):
        return str(git(repo, "rev-parse", "--verify", f"{commit}^"))
    return EMPTY_TREE


def zero_sha(ref: str) -> bool:
    text = ref.strip().lower()
    return bool(text) and set(text) <= {"0"}


def prepare_from_diff(cfg: Config) -> None:
    """Lås basen till pushens before innan någon anslutning öppnas."""
    if not cfg.base or zero_sha(cfg.base):
        raise DeployError("push-diff saknar giltig before-commit. Ingen live-fil rördes.")
    try:
        cfg.base = resolve_commit(cfg.repo, cfg.base)
    except DeployError as err:
        raise DeployError(f"{err}. Ingen live-fil rördes.") from err


def ensure_on_main(cfg: Config) -> None:
    if cfg.allow_any_commit:
        return
    has_main = git_ok(cfg.repo, "rev-parse", "--verify", "--quiet", "origin/main")
    if not has_main:
        if cfg.require_main:
            raise DeployError("origin/main saknas. Deploy avbryts.")
        log("Varning: origin/main finns inte här, så släktskapet kontrollerades inte.")
        return
    if not git_ok(cfg.repo, "merge-base", "--is-ancestor", cfg.commit, "origin/main"):
        raise DeployError(
            f"{cfg.commit[:12]} finns inte på origin/main. Bara main deployas."
        )


def plan_diff(cfg: Config) -> list[Item]:
    raw = git(cfg.repo, "diff", "--name-status", "-z", "-M", cfg.base, cfg.commit, text=False)
    assert isinstance(raw, bytes)
    items: list[Item] = []
    for code, path, old in parse_name_status_z(raw):
        path = safe_rel(path)
        if code == "D":
            items.append(Item("keep-deleted", path, "borttagen i git, lämnas kvar på servern"))
            continue
        if code in {"R", "C"} and old:
            old_rel = safe_rel(old)
            items.append(
                Item(
                    "keep-deleted",
                    old_rel,
                    "gammalt namn efter flytt, raderas inte på servern",
                    old_rel,
                )
            )
        reason = deny_reason(path)
        if reason:
            items.append(Item("skip", path, reason))
            continue
        if code not in {"A", "M", "R", "C", "T"}:
            items.append(Item("skip", path, f"okänd git-status {code}"))
            continue
        items.append(Item("deploy", path))
    return items


def plan_explicit(cfg: Config) -> list[Item]:
    items: list[Item] = []
    for raw_path in cfg.paths:
        path = safe_rel(raw_path)
        reason = deny_reason(path)
        if reason:
            items.append(Item("deny", path, reason))
            continue
        if not git_ok(cfg.repo, "cat-file", "-e", f"{cfg.commit}:{path}"):
            items.append(Item("missing", path, "finns inte i committen"))
            continue
        items.append(Item("deploy", path))
    return items


def blob(repo: str, commit: str, path: str) -> bytes:
    try:
        data = git(repo, "show", f"{commit}:{path}", text=False)
    except subprocess.CalledProcessError as err:
        raise DeployError(f"kan inte läsa {path} från {commit[:12]}") from err
    assert isinstance(data, bytes)
    return data


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def read_loopia_ftp_env() -> tuple[str, str, str, int]:
    """Host, användare, lösenord och port som deploy loggar in med.

    GitHub-secrets kan ha inledande mellanslag eller radbrytning. De trimmas
    här, en gång, innan ``connect_with_credentials``. Värdena skrivs inte ut.
    """
    host = os.environ.get("LOOPIA_FTP_HOST", "").strip()
    user = os.environ.get("LOOPIA_FTP_USER", "").strip()
    password = os.environ.get("LOOPIA_FTP_PASS", "").strip()
    port = int(os.environ.get("LOOPIA_FTP_PORT", "21"))
    return host, user, password, port


def connect_with_credentials(
    *,
    host: str,
    user: str,
    password: str,
    port: int,
    timeout: float,
    insecure: bool = False,
) -> SessionFTP:
    """Explicit FTPS. Samma anrop som ``connect`` använder åt deploy."""
    context = ssl.create_default_context()
    if insecure:
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
    ftp = SessionFTP(context=context)
    ftp.connect(host, port, timeout=timeout)
    try:
        ftp.login(user, password)
    except FTP_ERRORS as err:
        try:
            ftp.close()
        except Exception:
            pass
        raise DeployError("FTP-inloggning nekades") from err
    ftp.prot_p()
    ftp.set_pasv(True)
    ftp.voidcmd("TYPE I")
    ftp.encoding = "utf-8"
    if ftp.sock is not None:
        ftp.sock.settimeout(timeout)
    return ftp


def connect(cfg: Config) -> SessionFTP:
    return connect_with_credentials(
        host=cfg.host,
        user=cfg.user,
        password=cfg.password,
        port=cfg.port,
        timeout=cfg.timeout,
        insecure=cfg.insecure,
    )


def close_ftp(ftp: SessionFTP | None) -> None:
    if ftp is None:
        return
    try:
        ftp.quit()
    except Exception:
        try:
            ftp.close()
        except Exception:
            pass


def truthy(value: str) -> bool:
    return value.strip().lower() in {"1", "true", "yes", "ja", "on"}


def is_smoke_name(name: str) -> bool:
    base = posixpath.basename(name).lower()
    if base in SMOKE_BASENAMES:
        return True
    return base.startswith("deploy-check.") or base.startswith("smoke.")


def is_transfer_junk(name: str) -> bool:
    """Skräp från avbrutna överföringar och tidigare körningar.

    Träffar ``*.bak``, ``*.bak*`` (``.htaccess.bak``, ``.user.ini.bak1``),
    ``*.uploading`` / ``.*.uploading`` och egna ``*.deploy-*.tmp``.
    """
    base = posixpath.basename(name)
    if not base or base in {".", ".."} or "/" in base or base == "." or ".." in base.split("/"):
        return False
    lower = base.lower()
    if lower.endswith(".uploading"):
        return True
    if _BAK_SUFFIX.search(lower):
        return True
    if ".deploy-" in lower and lower.endswith(".tmp"):
        return True
    return False


def _display(rel_dir: str, name: str) -> str:
    return posixpath.join(rel_dir, name) if rel_dir else name


def trash_day() -> str:
    """Datum i papperskorgens sökväg. ``JEAB_DEPLOY_TRASH_DAY`` låser tester."""
    raw = os.environ.get("JEAB_DEPLOY_TRASH_DAY", "").strip()
    if raw:
        if _TRASH_DAY.fullmatch(raw) is None:
            raise DeployError(f"JEAB_DEPLOY_TRASH_DAY måste vara YYYY-MM-DD, fick {raw}")
        return raw
    return time.strftime("%Y-%m-%d", time.gmtime())


def trash_rel(src_rel: str, day: str | None = None) -> str:
    """``src/ordrar.php.bak`` → ``data/.private/trash/<dag>/src/ordrar.php.bak``."""
    chosen = trash_day() if day is None else day.strip()
    if _TRASH_DAY.fullmatch(chosen) is None:
        raise DeployError(f"ogiltigt papperskorgsdatum: {day}")
    rel = src_rel.replace("\\", "/").strip()
    if rel.startswith("/") or "\x00" in rel:
        raise DeployError(f"ogiltig sökväg till papperskorgen: {src_rel}")
    while rel.startswith("./"):
        rel = rel[2:]
    rel = rel.strip("/")
    if not rel:
        raise DeployError(f"ogiltig sökväg till papperskorgen: {src_rel}")
    parts = [part for part in rel.split("/") if part not in {"", "."}]
    if any(part == ".." for part in parts):
        raise DeployError(f"ogiltig sökväg till papperskorgen: {src_rel}")
    normalized = "/".join(parts)
    prefix = _TRASH_ROOT + "/"
    if normalized == _TRASH_ROOT or normalized.startswith(prefix):
        raise DeployError(f"redan i papperskorgen: {src_rel}")
    return f"{_TRASH_ROOT}/{chosen}/{normalized}"


def _trash_candidate(src_rel: str, day: str, n: int) -> str:
    dest = trash_rel(src_rel, day)
    if n <= 1:
        return dest
    return f"{dest}-{n}"


def _guard_tmp_name(name: str) -> None:
    if ".tmp" not in name or ".deploy-" not in name:
        raise DeployError("vägrar skriva på ett live-namn")


class FtpOps:
    """FTP-kommandon i en session. Bytet görs utan extra kommando mellan rename."""

    name = "ftp"

    def __init__(self, cfg: Config) -> None:
        self.cfg = cfg
        self.ftp: SessionFTP | None = None
        self.cwd: str | None = None

    def close(self) -> None:
        close_ftp(self.ftp)
        self.ftp = None
        self.cwd = None

    def session(self, rel_dir: str) -> SessionFTP:
        if self.ftp is None:
            self.ftp = connect(self.cfg)
            self.cwd = None
        if self.cwd != rel_dir:
            enter_dir(self.ftp, self.cfg.root, rel_dir)
            self.cwd = rel_dir
        return self.ftp

    def _call(self, rel_dir: str, fn):
        try:
            return fn(self.session(rel_dir))
        except MissingFile:
            raise
        except (OSError, EOFError, *FTP_ERRORS):
            self.close()
            raise

    def exists(self, rel_dir: str, name: str) -> bool:
        return bool(self._call(rel_dir, lambda ftp: remote_exists(ftp, name)))

    def read(self, rel_dir: str, name: str) -> bytes:
        def run(ftp: SessionFTP) -> bytes:
            if not remote_exists(ftp, name):
                raise MissingFile(name)
            return retr_bytes(ftp, name, self.cfg.blocksize)

        return self._call(rel_dir, run)

    def read_or_none(self, rel_dir: str, name: str) -> bytes | None:
        if not self.exists(rel_dir, name):
            return None
        return self.read(rel_dir, name)

    def write_tmp(self, rel_dir: str, name: str, data: bytes) -> None:
        _guard_tmp_name(name)

        def run(ftp: SessionFTP) -> None:
            if remote_exists(ftp, name):
                ftp.delete(name)
            stor_bytes(ftp, name, data, self.cfg.blocksize)

        self._call(rel_dir, run)

    def write_flag(self, data: bytes) -> None:
        """Skriv private/deploying.flag på plats. Ingen rename, ingen delete."""

        def run(ftp: SessionFTP) -> None:
            stor_bytes(ftp, DEPLOY_FLAG_NAME, data, self.cfg.blocksize)

        self._call(DEPLOY_FLAG_DIR, run)

    def rename(self, rel_dir: str, src: str, dst: str) -> None:
        self._call(rel_dir, lambda ftp: ftp.rename(src, dst))

    def relocate(self, src_rel: str, dst_rel: str) -> None:
        """RNFR/RNTO från webbroten. Skapar målkatalogen. Raderar inte källan själv."""
        src_rel = src_rel.replace("\\", "/").strip("/")
        dst_rel = dst_rel.replace("\\", "/").strip("/")
        if (
            not src_rel
            or not dst_rel
            or ".." in src_rel.split("/")
            or ".." in dst_rel.split("/")
        ):
            raise DeployError("ogiltig flytt")
        dest_dir = posixpath.dirname(dst_rel)

        def run(ftp: SessionFTP) -> None:
            if dest_dir:
                enter_dir(ftp, self.cfg.root, dest_dir)
            enter_root(ftp, self.cfg.root)
            self.cwd = ""
            ftp.rename(src_rel, dst_rel)

        try:
            if self.ftp is None:
                self.ftp = connect(self.cfg)
                self.cwd = None
            run(self.ftp)
        except MissingFile:
            raise
        except (OSError, EOFError, *FTP_ERRORS):
            self.close()
            raise

    def delete(self, rel_dir: str, name: str) -> None:
        self._call(rel_dir, lambda ftp: try_delete(ftp, name))

    def list_names(self, rel_dir: str) -> list[str]:
        def run(ftp: SessionFTP) -> list[str]:
            lines: list[str] = []
            ftp.retrlines("NLST", lines.append)
            restore_binary(ftp)
            return sorted(
                {
                    posixpath.basename(line.rstrip("/"))
                    for line in lines
                    if line and posixpath.basename(line.rstrip("/")) not in {".", ".."}
                }
            )

        try:
            return self._call(rel_dir, run)
        except MissingFile:
            raise
        except (OSError, EOFError, *FTP_ERRORS) as err:
            raise DeployError(f"listning av {rel_dir or '/'} misslyckades") from err


class TinyOps:
    name = "tinyfm"

    def __init__(self, client: TinyFmClient) -> None:
        self.client = client

    def close(self) -> None:
        return None

    def exists(self, rel_dir: str, name: str) -> bool:
        try:
            return self.client.exists(rel_dir, name)
        except TinyFmError as err:
            raise DeployError(str(err)) from err

    def read(self, rel_dir: str, name: str) -> bytes:
        try:
            return self.client.read(rel_dir, name)
        except TinyFmError as err:
            if str(err).startswith("saknar "):
                raise MissingFile(str(err)) from err
            raise DeployError(str(err)) from err

    def read_or_none(self, rel_dir: str, name: str) -> bytes | None:
        try:
            return self.client.read(rel_dir, name)
        except TinyFmError as err:
            if str(err).startswith("saknar "):
                return None
            raise DeployError(str(err)) from err

    def write_tmp(self, rel_dir: str, name: str, data: bytes) -> None:
        try:
            self.client.write_tmp(rel_dir, name, data)
        except TinyFmError as err:
            raise DeployError(str(err)) from err

    def save_live(self, rel_dir: str, name: str, data: bytes) -> None:
        try:
            self.client.save_live(rel_dir, name, data)
        except TinyFmError as err:
            raise DeployError(str(err)) from err

    def rename(self, rel_dir: str, src: str, dst: str) -> None:
        try:
            self.client.rename(rel_dir, src, dst)
        except TinyFmError as err:
            raise DeployError(str(err)) from err

    def relocate(self, src_rel: str, dst_rel: str) -> None:
        try:
            self.client.relocate(src_rel, dst_rel)
        except TinyFmError as err:
            raise DeployError(str(err)) from err

    def delete(self, rel_dir: str, name: str) -> None:
        try:
            self.client.delete(rel_dir, name)
        except TinyFmError as err:
            raise DeployError(str(err)) from err

    def list_names(self, rel_dir: str) -> list[str]:
        try:
            return self.client.list_names(rel_dir)
        except TinyFmError as err:
            raise DeployError(str(err)) from err

    def write_flag(self, data: bytes) -> None:
        try:
            self.client.write_flag(DEPLOY_FLAG_DIR, DEPLOY_FLAG_NAME, data)
        except TinyFmError as err:
            raise DeployError(str(err)) from err


def probe_ftp(cfg: Config) -> bool:
    """Inloggning och PWD. Ingen STOR, ingen rename, ingen live-fil."""
    original = cfg.timeout
    cfg.timeout = cfg.ftp_probe_timeout
    ftp: SessionFTP | None = None
    try:
        ftp = connect(cfg)
        pwd = ftp.pwd()
        if not isinstance(pwd, str) or pwd == "":
            log("FTP-kontrollen misslyckades: PWD gav inget svar. Ingen live-fil rördes.")
            return False
        log("FTP-kontroll ok: inloggning och PWD svarade")
        return True
    except Exception as err:
        log(f"FTP-kontrollen misslyckades: {type(err).__name__}. Ingen live-fil rördes.")
        return False
    finally:
        cfg.timeout = original
        close_ftp(ftp)


def missing_ftp_secrets(cfg: Config) -> list[str]:
    missing = []
    if not cfg.host:
        missing.append("LOOPIA_FTP_HOST")
    if not cfg.user:
        missing.append("LOOPIA_FTP_USER")
    if not cfg.password:
        missing.append("LOOPIA_FTP_PASS")
    return missing


def ftp_ready(cfg: Config) -> bool:
    return not missing_ftp_secrets(cfg)


def start_tinyfm(cfg: Config) -> TinyOps:
    client = TinyFmClient(
        cfg.tinyfm_url,
        cfg.tinyfm_user,
        cfg.tinyfm_pass,
        cfg.campaign,
        gate_id=cfg.gate_id,
        gap=cfg.tinyfm_gap,
        timeout=max(cfg.http_timeout, 30),
        log=log,
        mask=mask_value,
    )
    try:
        client.login()
    except TinyFmError as err:
        raise DeployError(str(err)) from err
    log("transport tinyfm")
    return TinyOps(client)


def open_transport(cfg: Config) -> FtpOps | TinyOps:
    if cfg.transport == "tinyfm":
        return start_tinyfm(cfg)
    if cfg.transport not in {"ftp", "auto"}:
        raise DeployError(f"okänd transport {cfg.transport}")
    missing = missing_ftp_secrets(cfg)
    if missing:
        log(
            "FTP-secret saknas: "
            + ", ".join(missing)
            + ". Hoppar över ftp och kör tinyfm. Ingen FTP-anslutning gjordes."
        )
        return start_tinyfm(cfg)
    if not probe_ftp(cfg):
        if cfg.transport == "ftp":
            raise DeployError(
                "FTP-kontrollen misslyckades. Ingen live-fil rördes. transport=ftp stoppar utan tinyfm."
            )
        log("FTP-kontrollen misslyckades. Växlar till tinyfm. Ingen live-fil rördes.")
        return start_tinyfm(cfg)
    log("transport ftp")
    return FtpOps(cfg)


def enter_root(ftp: SessionFTP, root: str) -> None:
    root = root.strip() or "/"
    candidates = [root]
    if root.startswith("/"):
        stripped = root.lstrip("/")
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
    raise DeployError("Kunde inte öppna fjärrroten. Kontrollera LOOPIA_FTP_ROOT.") from last


def enter_dir(ftp: SessionFTP, root: str, rel_dir: str) -> None:
    enter_root(ftp, root)
    for part in [piece for piece in rel_dir.split("/") if piece and piece != "."]:
        try:
            ftp.cwd(part)
        except FTP_ERRORS:
            ftp.mkd(part)
            ftp.cwd(part)


def enter_existing_dir(ftp: SessionFTP, root: str, rel_dir: str) -> None:
    enter_root(ftp, root)
    for part in [piece for piece in rel_dir.split("/") if piece and piece != "."]:
        try:
            ftp.cwd(part)
        except FTP_ERRORS as err:
            raise DeployError(f"katalogen {rel_dir or '/'} finns inte") from err


def restore_binary(ftp: SessionFTP) -> None:
    """NLST/LIST via retrlines sätter TYPE A. SIZE nekas då, och PHP-filer får inte CRLF."""
    try:
        ftp.voidcmd("TYPE I")
    except FTP_ERRORS:
        pass


def remote_exists(ftp: SessionFTP, name: str) -> bool:
    restore_binary(ftp)
    try:
        ftp.size(name)
        return True
    except FTP_ERRORS as err:
        message = str(err)
        if message.startswith("500") or message.startswith("502") or "ASCII" in message:
            pass
        else:
            return False
    names: list[str] = []
    try:
        ftp.retrlines("NLST", names.append)
    except FTP_ERRORS:
        return False
    finally:
        restore_binary(ftp)
    bases = {posixpath.basename(line.rstrip("/")) for line in names}
    return name in bases


def try_delete(ftp: SessionFTP, name: str) -> None:
    if remote_exists(ftp, name):
        ftp.delete(name)


def retr_bytes(ftp: SessionFTP, name: str, blocksize: int) -> bytes:
    chunks: list[bytes] = []

    def collect(chunk: bytes) -> None:
        chunks.append(chunk)

    ftp.retrbinary(f"RETR {name}", collect, blocksize=blocksize)
    return b"".join(chunks)


def retr_hash(ftp: SessionFTP, name: str, blocksize: int) -> tuple[int, str]:
    data = retr_bytes(ftp, name, blocksize)
    return len(data), sha256(data)


def stor_bytes(ftp: SessionFTP, name: str, data: bytes, blocksize: int) -> None:
    ftp.storbinary(f"STOR {name}", io.BytesIO(data), blocksize=blocksize)


def http_get(url: str, timeout: float) -> tuple[int, bytes]:
    request = urllib.request.Request(
        url,
        headers={"User-Agent": "JEAB-loopia-deploy/1", "Cache-Control": "no-cache"},
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return int(response.status), response.read()
    except urllib.error.HTTPError as err:
        return int(err.code), err.read()
    except (urllib.error.URLError, TimeoutError) as err:
        raise GateError(f"HTTP-kontrollen gick inte att nå: {err}") from err


def http_post(url: str, payload: bytes, timeout: float, user_agent: str) -> tuple[int, bytes]:
    request = urllib.request.Request(
        url,
        data=payload,
        method="POST",
        headers={
            "User-Agent": user_agent,
            "Content-Type": "application/json",
            "Cache-Control": "no-cache",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return int(response.status), response.read()
    except urllib.error.HTTPError as err:
        return int(err.code), err.read()
    except (urllib.error.URLError, TimeoutError) as err:
        raise GateError(f"HTTP-kontrollen gick inte att nå: {err}") from err


def hook_url_for(gate_url: str) -> str:
    override = os.environ.get("JEAB_DEPLOY_HOOK_URL", "").strip()
    if override:
        return override
    parts = urllib.parse.urlsplit(gate_url)
    return urllib.parse.urlunsplit((parts.scheme, parts.netloc, "/hook.php", "", ""))


def classify_hook_response(status: int, body: bytes) -> bool:
    """401 och JSON med ok=false. Inget annat är en frisk hook."""
    if status != 401:
        return False
    try:
        parsed = json.loads(body.decode("utf-8"))
    except (UnicodeError, json.JSONDecodeError, TypeError):
        return False
    return isinstance(parsed, dict) and parsed.get("ok") is False


def classify_gate_response(status: int, body: bytes, needle: str) -> str:
    """green, unclear eller rollback. Ett enda grindsvärde efter hela batchen."""
    text = body.decode("utf-8", "replace")
    if status == 200 and needle in text:
        return "green"
    if status == 403 and "blocked page" in text.lower():
        return "unclear"
    if status >= 500 or status == 200:
        return "rollback"
    return "unclear"


def read_gate(cfg: Config) -> tuple[str, str]:
    log(f"ett grindanrop: {cfg.gate_url}")
    try:
        status, body = http_get(cfg.gate_url, cfg.http_timeout)
    except GateError as err:
        return (
            "unclear",
            f"OKLART: {err}. Filerna är sha-verifierade via FTP. Ingen rollback. .bak lämnas kvar.",
        )
    kind = classify_gate_response(status, body, cfg.needle)
    found = "ja" if cfg.needle.encode() in body else "nej"
    if kind == "green":
        hook_url = hook_url_for(cfg.gate_url)
        log(f"hook-kontroll: POST {hook_url}")
        try:
            hook_status, hook_body = http_post(hook_url, b"{}", cfg.http_timeout, HOOK_UA)
        except GateError as err:
            return (
                "unclear",
                f"OKLART: hook {err}. «{cfg.needle}» finns. Ingen rollback. .bak lämnas kvar.",
            )
        if classify_hook_response(hook_status, hook_body):
            return "green", f"grind grön: HTTP 200, «{cfg.needle}» finns; hook HTTP 401 ok:false"
        if hook_status == 403 and b"blocked page" in hook_body.lower():
            return (
                "unclear",
                "OKLART: hook HTTP 403 med Loopia-block (Blocked Page). "
                "Ingen rollback. .bak lämnas kvar.",
            )
        return (
            "rollback",
            f"hook kräver rollback: HTTP {hook_status}, ok:false saknas",
        )
    if kind == "unclear" and status == 403 and b"blocked page" in body.lower():
        return (
            "unclear",
            "OKLART: HTTP 403 med Loopia-block (Blocked Page). "
            "Filerna är sha-verifierade via FTP. Ingen rollback. .bak lämnas kvar.",
        )
    if kind == "unclear":
        return (
            "unclear",
            f"OKLART: HTTP {status} är inte ett tydligt appfel. "
            "Filerna är sha-verifierade via FTP. Ingen rollback. .bak lämnas kvar.",
        )
    return (
        "rollback",
        f"grind kräver rollback: HTTP {status}, «{cfg.needle}»={found}",
    )


_REQUIRE_RE = re.compile(
    r"""require(?:_once)?\s+__DIR__\s*\.\s*['"](/[^'"]+\.php)['"]"""
)
_BOOTSTRAP_LOAD_FUNCS = (
    "jeab_bootstrap_core_files",
    "jeab_bootstrap_legacy_files",
    "jeab_bootstrap_light_pages",
)
_PHP_FILE_LITERAL = re.compile(r"""['"]([^'"\\]+\.php)['"]""")


def _php_function_body(text: str, name: str) -> str | None:
    """Kroppen mellan första ``{`` och matchande ``}`` för ``function name``."""
    marker = f"function {name}"
    start = 0
    while True:
        found = text.find(marker, start)
        if found < 0:
            return None
        after = found + len(marker)
        if after >= len(text) or text[after] in " \t\n\r(:":
            break
        start = after
    brace = text.find("{", after)
    if brace < 0:
        return None
    depth = 0
    for index in range(brace, len(text)):
        char = text[index]
        if char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return text[brace + 1 : index]
    return None


def _bootstrap_array_files(text: str) -> list[str]:
    """Filnamn i kärnan, legacy-listan och light_pages-värdena.

    Okänd sida laddar legacy. Varje träff laddar kärnan plus sidans värden.
    Bara de tre arrayerna: action-extralistorna hör inte till boot-grinden.
    """
    found: list[str] = []
    for name in _BOOTSTRAP_LOAD_FUNCS:
        body = _php_function_body(text, name)
        if not body:
            continue
        found.extend(match.group(1) for match in _PHP_FILE_LITERAL.finditer(body))
    return found


def required_php(repo: str, rel: str) -> list[str]:
    """PHP-filer som ``rel`` drar in.

    Vanliga ``require``/``require_once`` med ``__DIR__`` följs som tidigare.
    ``bootstrap.php`` laddar dessutom kärnan, legacy-listan (okänd sida) och
    alla filer i ``light_pages``-värdena från arrayer. De får samma ``src/``-
    prefix som en ``__DIR__``-rad och kedjan följs vidare därifrån.
    """
    full = os.path.join(repo, rel)
    if not os.path.isfile(full):
        return []
    with open(full, encoding="utf-8", errors="replace") as handle:
        text = handle.read()
    parent = os.path.dirname(rel).replace("\\", "/")
    found: list[str] = []
    seen: set[str] = set()

    def add(raw: str) -> None:
        child = posixpath.normpath(posixpath.join(parent, raw.lstrip("/")))
        if not child.endswith(".php") or child.startswith("..") or child in seen:
            return
        seen.add(child)
        found.append(child)

    for match in _REQUIRE_RE.finditer(text):
        add(match.group(1))
    if any(name in text for name in _BOOTSTRAP_LOAD_FUNCS):
        for name in _bootstrap_array_files(text):
            add(name)
    return found


def is_deploy_entry(path: str) -> bool:
    """Ingångar som ska upp sist: hookar, index, omtago och kalkyl utanför src/."""
    norm = path.replace("\\", "/").lstrip("/")
    if norm in _ENTRY_EXACT:
        return True
    if norm.startswith("src/"):
        return False
    base = posixpath.basename(norm).lower()
    if not base.endswith(".php"):
        return False
    return base.startswith("omtago") or "kalkyl" in base


_REWRITE_ON = re.compile(r"(?i)RewriteEngine\s+On\b")
_REWRITE_FORBID = re.compile(r"(?i)RewriteRule\s+\S+\s+-\s+\[[^\]]*F")


def htaccess_blocks_private(body: bytes | None) -> bool:
    """Full spärr för Apache 2.4 och 2.2.

    Require, Order deny,allow, Deny och Options -Indexes krävs.
    RewriteEngine On utan en [F]-regel är inte en spärr: den slår ut
    rotens RewriteRule för katalogen.
    """
    if not body:
        return False
    text = body.decode("utf-8", "replace")
    if "Require all denied" not in text or "Deny from all" not in text:
        return False
    if "Order deny,allow" not in text or "Options -Indexes" not in text:
        return False
    if _REWRITE_ON.search(text) and not _REWRITE_FORBID.search(text):
        return False
    return True


def prepend_private_htaccess(items: list[Item], body: bytes | None, in_commit: bool) -> list[Item]:
    """Lägg private/.htaccess först bland filerna om spärren saknas."""
    if any(item.path == PRIVATE_HTACCESS and item.action == "deploy" for item in items):
        return items
    if not in_commit or deny_reason(PRIVATE_HTACCESS) is not None or htaccess_blocks_private(body):
        return items
    return [Item("deploy", PRIVATE_HTACCESS, "private/ ska inte vara läsbar")] + list(items)


def private_htaccess_in_commit(cfg: Config, rel: str = PRIVATE_HTACCESS) -> bool:
    if not cfg.commit:
        return False
    return git_ok(cfg.repo, "cat-file", "-e", f"{cfg.commit}:{rel}")


def apply_one_private_htaccess(cfg: Config, items: list[Item], rel: str) -> list[Item]:
    """Ta med en spärrfil när den saknas på servern eller inte nekar."""
    if not private_htaccess_in_commit(cfg, rel):
        return items
    if any(item.path == rel and item.action == "deploy" for item in items):
        return items
    if deny_reason(rel) is not None:
        return items
    folder, name = posixpath.split(rel)
    try:
        body = cfg.ops.read_or_none(folder, name)
    except (DeployError, MissingFile) as err:
        log(f"{rel} kontrollerades inte: {err}")
        return items
    if htaccess_blocks_private(body):
        log(f"{rel} spärrar redan")
        return items
    log(f"{rel} saknas eller spärrar inte")
    if rel == PRIVATE_HTACCESS:
        return prepend_private_htaccess(items, body, True)
    return [Item("deploy", rel, "private/ ska inte vara läsbar")] + list(items)


def apply_private_htaccess(cfg: Config, items: list[Item]) -> list[Item]:
    """Ta med spärrfilerna när de saknas på servern eller inte nekar."""
    for rel in PRIVATE_HTACCESS_FILES:
        items = apply_one_private_htaccess(cfg, items, rel)
    return items


def order_deploy_paths(repo: str, paths: list[str]) -> list[str]:
    """Fast ordning: src/ utan pages, src/pages, övrigt, assets/ sist.

    Inom src/ (utom pages) kommer require-mål före den som drar in dem.
    Deployen 2026-10-21 sorterade alfabetiskt, så ``src/bootstrap.php`` byttes
    innan ``src/test_lead.php`` fanns. ``assets/`` sist så PHP ligger på plats
    innan CSS och JS byts.
    """
    ordered = _order_by_require(repo, paths)
    src_core: list[str] = []
    src_pages: list[str] = []
    assets: list[str] = []
    mid: list[str] = []
    entries: list[str] = []
    for path in ordered:
        norm = path.replace("\\", "/")
        if norm.startswith("src/pages/"):
            src_pages.append(path)
        elif norm.startswith("src/"):
            src_core.append(path)
        elif norm.startswith("assets/"):
            assets.append(path)
        elif is_deploy_entry(path):
            entries.append(path)
        else:
            mid.append(path)
    return src_core + src_pages + mid + entries + assets


def is_runtime_path(path: str) -> bool:
    """Filer som sajten kör. Tester, docs och spärrade sökvägar ingår inte."""
    norm = normalize_rel(path)
    if not norm or norm.startswith("tests/") or deny_reason(norm) is not None:
        return False
    if norm.startswith("src/") or norm.startswith("assets/"):
        return True
    return is_deploy_entry(norm)


def changed_runtime_missing(repo: str, since: str, head: str, planned: set[str]) -> list[str]:
    """Körfiler i since..head som inte står i den här uppladdningens paths."""
    raw = git(repo, "diff", "--name-only", "-z", f"{since}..{head}", text=False)
    if not isinstance(raw, (bytes, bytearray)):
        raw = str(raw).encode()
    missing: list[str] = []
    for part in raw.split(b"\0"):
        if not part:
            continue
        path = part.decode("utf-8", "replace").replace("\\", "/").lstrip("/")
        if is_runtime_path(path) and path not in planned:
            missing.append(path)
    return missing


def warn_missing_runtime(cfg: Config, planned: set[str]) -> None:
    """Valfri since_sha. Varning, inte avbrott."""
    if not cfg.since_sha:
        return
    since = resolve_commit(cfg.repo, cfg.since_sha)
    missing = changed_runtime_missing(cfg.repo, since, cfg.commit, planned)
    if not missing:
        log(f"since_sha {since[:12]}: ändrade körfiler finns i paths")
        return
    listing = ", ".join(missing)
    message = f"körfiler ändrade sedan {since[:12]} saknas i paths: {listing}"
    log(f"::warning::{message}")
    summary = os.environ.get("GITHUB_STEP_SUMMARY", "").strip()
    if summary:
        with open(summary, "a", encoding="utf-8") as handle:
            handle.write(f"\n::warning::{message}\n")


def announce_dry_run(cfg: Config) -> None:
    """Torr deploy ska synas som fel i sammanfattningen. Inget laddas upp."""
    if cfg.mode != "deploy" or not cfg.dry_run:
        return
    message = "TORRKÖRNING – INGET UPPLADDAT"
    log(f"::error::{message}")
    summary = os.environ.get("GITHUB_STEP_SUMMARY", "").strip()
    if summary:
        with open(summary, "a", encoding="utf-8") as handle:
            handle.write(f"\n::error::{message}\n")


def verify_live_against_commit(cfg: Config, items: list[Item]) -> None:
    """Efter uppladdningen: varje path ska ha commitens sha på servern."""
    mismatches: list[str] = []
    checked = 0
    for item in items:
        if item.action != "deploy":
            continue
        checked += 1
        expected = blob(cfg.repo, cfg.commit, item.path)
        want = sha256(expected)
        rel_dir = posixpath.dirname(item.path)
        base = posixpath.basename(item.path)
        try:
            live = cfg.ops.read(rel_dir, base)
        except MissingFile:
            mismatches.append(f"{item.path} saknas")
            continue
        got = sha256(live)
        if got != want or len(live) != len(expected):
            mismatches.append(f"{item.path} live {got} commit {want}")
    if mismatches:
        raise DeployError("live sha skiljer sig från committen: " + "; ".join(mismatches))
    log(f"live sha matchar committen för {checked} filer")


def _order_by_require(repo: str, paths: list[str]) -> list[str]:
    """Dependenser före filen som require:ar dem. Samma nivå sorteras alfabetiskt."""
    wanted = list(dict.fromkeys(paths))
    present = set(wanted)
    remaining = {path: set() for path in wanted}
    for path in wanted:
        for child in required_php(repo, path):
            if child in present and child != path:
                remaining[path].add(child)
    ordered: list[str] = []
    while remaining:
        ready = sorted(path for path, need in remaining.items() if not need)
        if not ready:
            ordered.extend(sorted(remaining))
            break
        nxt = ready[0]
        ordered.append(nxt)
        del remaining[nxt]
        for need in remaining.values():
            need.discard(nxt)
    return ordered


def boot_critical_paths(repo: str) -> set[str]:
    """Filer som filer.php måste kunna ladda. Saknas en av dem är TinyFM död.

    Krav följs ett steg i taget. ``bootstrap.php`` drar in kärnan, legacy-listan
    och ``light_pages`` via arrayer (``auth.php``, ``inkorg.php``, …) plus
    vanliga ``require``. ``inkorg.php`` drar i sin tur in ``inkorg_contacts.php``.
    Att bara läsa den första filen lämnar den kedjan utanför, och ett rename
    tar då ner hela sajten innan bytet hinner bli klart.
    """
    paths = set(_BOOT_ALWAYS)
    queue = list(paths)
    seen: set[str] = set()
    while queue:
        rel = queue.pop()
        if rel in seen:
            continue
        seen.add(rel)
        for child in required_php(repo, rel):
            if child not in paths:
                paths.add(child)
                queue.append(child)
    return paths


def is_boot_critical(repo: str, path: str) -> bool:
    return path in boot_critical_paths(repo)


def lint_php(payload: bytes) -> None:
    if not payload.startswith(b"<?php"):
        return
    fd, name = tempfile.mkstemp(suffix=".php")
    try:
        os.write(fd, payload)
        os.close(fd)
        fd = -1
        proc = subprocess.run(
            ["php", "-l", name],
            check=False,
            capture_output=True,
            text=True,
        )
        if proc.returncode != 0:
            detail = (proc.stderr or proc.stdout or "php -l fel").strip()
            raise DeployError(f"php -l nekade boot-filen: {detail}")
    finally:
        if fd >= 0:
            os.close(fd)
        try:
            os.unlink(name)
        except OSError:
            pass


def stale_bak_names(base: str, names: list[str]) -> list[str]:
    """``.bak`` och ``.bak*`` som hör till ``base`` och blockerar bytet.

    ``bookings.php.bak``, ``bookings.php.bak1`` och ``bookings.php.bak-old``
    hör till ``bookings.php``. Live-filen, tmp-filer och en annan fils backup
    gör det inte.
    """
    found: list[str] = []
    seen: set[str] = set()
    for name in names:
        if not name or name in {".", "..", base} or "/" in name or "\\" in name:
            continue
        match = _BAK_SUFFIX.search(name)
        if not match or name[: match.start()] != base:
            continue
        if name in seen:
            continue
        seen.add(name)
        found.append(name)
    return found


def live_stem_for_cleanup(path: str) -> str:
    """Live-namnet vars backup ``--mode cleanup-bak`` får radera."""
    base = posixpath.basename(path)
    match = _BAK_SUFFIX.search(base)
    if not match:
        return base
    stem = base[: match.start()]
    if not stem or stem in {".", ".."} or "/" in stem or "\\" in stem:
        raise DeployError(f"{path} är inte en backup som får städas")
    return stem


def _discard_tmp(ops: FtpOps | TinyOps, rel_dir: str, tmp: str) -> None:
    try:
        ops.delete(rel_dir, tmp)
    except WafBlocked:
        raise
    except DeployError as err:
        log(f"tmp kunde inte raderas: {err}")


def park_backup(ops: FtpOps | TinyOps, rel_dir: str, name: str, day: str | None = None) -> str:
    """Flytta en backup till dagens papperskorg. Skriver inte över en tidigare kopia."""
    chosen = trash_day() if day is None else day
    src_rel = _display(rel_dir, name)
    dest = ""
    for n in range(1, 51):
        candidate = _trash_candidate(src_rel, chosen, n)
        if not ops.exists(posixpath.dirname(candidate), posixpath.basename(candidate)):
            dest = candidate
            break
    if dest == "":
        raise DeployError(f"papperskorgen har redan 50 kopior av {src_rel}")
    ops.relocate(src_rel, dest)
    if ops.exists(rel_dir, name):
        raise DeployError(f"{src_rel} finns kvar efter flytt. Live-filen rördes inte.")
    log(f"flyttade {src_rel} till {dest}")
    return dest


def clear_stale_baks(ops: FtpOps | TinyOps, rel_dir: str, base: str, *, park: bool = False) -> list[str]:
    """Ta undan gammal ``{base}.bak`` och ``{base}.bak*``. Live-filen rörs inte.

    ``park`` flyttar till papperskorgen (mode=deploy). Annars raderas filen
    (mode=cleanup-bak). Anropas bara när live redan finns, så backupen inte
    är den enda kopian, och från ``cleanup-bak`` där operatören bett om radering.
    """
    if not base or base in {".", ".."} or "/" in base or "\\" in base:
        raise DeployError("vägrar röra backup utan ett live-namn")
    exact = f"{base}.bak"
    try:
        names = list(ops.list_names(rel_dir))
    except DeployError as err:
        log(f"listning av {rel_dir or '/'} misslyckades vid bak-städning: {err}")
        names = [exact] if ops.exists(rel_dir, exact) else []
    targets = stale_bak_names(base, names)
    if exact not in targets and ops.exists(rel_dir, exact):
        targets.append(exact)
    removed: list[str] = []
    for name in targets:
        match = _BAK_SUFFIX.search(name)
        if (
            name == base
            or "/" in name
            or "\\" in name
            or match is None
            or name[: match.start()] != base
        ):
            raise DeployError(f"vägrar röra {_display(rel_dir, name)}")
        label = _display(rel_dir, name)
        if park:
            log(
                f"gammal backup {label} finns redan. "
                "Flyttar den till papperskorgen och fortsätter. Live-filen rörs inte av flytten."
            )
            park_backup(ops, rel_dir, name)
        else:
            log(
                f"gammal backup {label} finns redan. "
                "Raderar den och fortsätter. Live-filen rörs inte av raderingen."
            )
            ops.delete(rel_dir, name)
            if ops.exists(rel_dir, name):
                raise DeployError(f"{label} finns kvar efter radering. Live-filen rördes inte.")
            log(f"raderade {label}")
        removed.append(name)
    return removed


def swap(ops: FtpOps | TinyOps, rel_dir: str, base: str, tmp: str, bak: str, live_existed: bool) -> str:
    if not live_existed:
        ops.rename(rel_dir, tmp, base)
        return "ny-fil"
    try:
        clear_stale_baks(ops, rel_dir, base, park=True)
    except DeployError:
        _discard_tmp(ops, rel_dir, tmp)
        raise
    if ops.exists(rel_dir, bak):
        _discard_tmp(ops, rel_dir, tmp)
        raise DeployError(f"{bak} finns kvar efter städning. Tmp raderades. Live-filen lämnades orörd.")
    ops.rename(rel_dir, base, bak)
    try:
        ops.rename(rel_dir, tmp, base)
    except Exception:
        try:
            ops.rename(rel_dir, bak, base)
        except Exception as restore_err:
            raise DeployError(
                "Bytet avbröts och live kunde inte återställas från .bak. "
                "Kontrollera filen för hand."
            ) from restore_err
        raise DeployError("tmp kunde inte döpas till live. Live återställdes från .bak.")
    return "bak"


def rollback(
    cfg: Config,
    ops: FtpOps | TinyOps,
    rel_dir: str,
    base: str,
    tmp: str,
    bak: str,
    bad: str,
    mode: str,
    previous: bytes | None,
) -> None:
    expect = sha256(previous) if previous is not None else None
    if mode == "ny-fil":
        ops.delete(rel_dir, base)
        ops.delete(rel_dir, tmp)
        ops.delete(rel_dir, bad)
        if ops.exists(rel_dir, base):
            raise DeployError("den nya filen kunde inte tas bort vid återställning")
        log(f"återställning: ny fil {base} borttagen")
        return
    if mode == "in-place":
        if previous is None or expect is None:
            raise DeployError("ingen tidigare kopia att lägga tillbaka på plats")
        if not isinstance(ops, TinyOps):
            raise DeployError("på-plats-återställning kräver TinyFM")
        ops.save_live(rel_dir, base, previous)
        try:
            ops.delete(rel_dir, tmp)
        except DeployError:
            pass
    elif mode == "bak":
        if not ops.exists(rel_dir, bak):
            raise DeployError("saknar .bak och kan inte återställa live")
        if ops.exists(rel_dir, base):
            ops.delete(rel_dir, bad)
            try:
                ops.rename(rel_dir, bak, base)
            except (DeployError, OSError, EOFError, *FTP_ERRORS):
                ops.rename(rel_dir, base, bad)
                ops.rename(rel_dir, bak, base)
        else:
            ops.rename(rel_dir, bak, base)
        ops.delete(rel_dir, tmp)
        ops.delete(rel_dir, bad)
        if ops.exists(rel_dir, bak):
            ops.delete(rel_dir, bak)
    elif mode == "rename-over":
        if previous is None or expect is None:
            raise DeployError("ingen lokal kopia att återställa efter rename-over")
        ops.delete(rel_dir, tmp)
        ops.write_tmp(rel_dir, tmp, previous)
        live_tmp = ops.read(rel_dir, tmp)
        if len(live_tmp) != len(previous) or sha256(live_tmp) != expect:
            ops.delete(rel_dir, tmp)
            raise DeployError("återställningskopian verifierades inte, live lämnas som den är")
        try:
            ops.rename(rel_dir, tmp, base)
        except (DeployError, OSError, EOFError, *FTP_ERRORS):
            ops.delete(rel_dir, bad)
            ops.rename(rel_dir, base, bad)
            ops.rename(rel_dir, tmp, base)
            ops.delete(rel_dir, bad)
        ops.delete(rel_dir, tmp)
    else:
        raise DeployError(f"okänt bytläge {mode}")
    if expect is None:
        return
    live = ops.read(rel_dir, base)
    if sha256(live) != expect:
        raise DeployError("återställningen gav fel sha256 på live-filen")
    log(f"återställning: {base} är tillbaka (sha256={expect})")


def confirm_unchanged(
    cfg: Config,
    rel_dir: str,
    base: str,
    previous: bytes | None,
) -> None:
    ops = cfg.ops
    if previous is None:
        if ops.exists(rel_dir, base):
            raise DeployError("live-filen skapades fast bytet inte kördes")
        log(f"live-filen {base} fanns inte och skapades inte")
        return
    live = ops.read(rel_dir, base)
    if sha256(live) != sha256(previous) or len(live) != len(previous):
        raise DeployError("live-filen ändrades fast bytet inte kördes")
    log(f"live-filen {base} rördes inte ({len(live)} byte)")


def delete_our_tmp(cfg: Config, rel_dir: str, tmp: str) -> None:
    cfg.ops.delete(rel_dir, tmp)


def delay_for(attempt: int, delays: list[float]) -> float:
    if not delays:
        return 0
    return delays[min(attempt - 1, len(delays) - 1)]


def deploy_one(cfg: Config, item: Item) -> Result:
    ops = cfg.ops
    payload = blob(cfg.repo, cfg.commit, item.path)
    digest = sha256(payload)
    size = len(payload)
    rel_dir = posixpath.dirname(item.path)
    base = posixpath.basename(item.path)
    tmp = f"{base}.deploy-{cfg.short}.tmp"
    bak = f"{base}.bak"
    bad = f"{base}.deploy-{cfg.short}.bad.tmp"
    cfg.touched.append((rel_dir, base))
    log(f"börjar {item.path} ({size} byte, sha256={digest})")
    attempts = 1 if isinstance(ops, TinyOps) else cfg.attempts

    if not isinstance(ops, TinyOps) and ops.exists(rel_dir, tmp):
        ops.delete(rel_dir, tmp)
        log(f"tog bort gammal tmp {tmp}")
    previous = ops.read_or_none(rel_dir, base)

    if previous is not None and sha256(previous) == digest and len(previous) == size:
        log(f"oförändrad på servern: {item.path}")
        return Result(item.path, size, digest, "ok oförändrad")

    last_error: Exception | None = None
    for attempt in range(1, attempts + 1):
        log(f"försök {attempt}/{attempts} {item.path}")
        mode: str | None = None
        retry = False
        try:
            if not isinstance(ops, TinyOps) and ops.exists(rel_dir, tmp):
                ops.delete(rel_dir, tmp)
            try:
                ops.write_tmp(rel_dir, tmp, payload)
                got = ops.read(rel_dir, tmp)
            except WafBlocked:
                raise
            except (OSError, EOFError, *FTP_ERRORS) as err:
                raise VerifyError(f"överföringen bröts: {err}") from err
            got_size, got_sha = len(got), sha256(got)
            if got_size != size or got_sha != digest:
                ops.delete(rel_dir, tmp)
                raise VerifyError(
                    f"tmp stämmer inte (storlek {got_size}/{size}, sha256 {got_sha})"
                )
            log(f"tmp verifierad {tmp} ({got_size} byte, sha256={got_sha})")
            if isinstance(ops, TinyOps) and is_boot_critical(cfg.repo, item.path):
                if previous is None:
                    ops.delete(rel_dir, tmp)
                    raise DeployError(
                        f"{item.path} saknas på servern. TinyFM får inte döpa bort en fil som filer.php behöver. "
                        "Lägg tillbaka src/ordrar.php från .bak eller .deploy-*.tmp i Loopia Kundzon."
                    )
                lint_php(payload)
                try:
                    clear_stale_baks(ops, rel_dir, base, park=True)
                except DeployError:
                    _discard_tmp(ops, rel_dir, tmp)
                    raise
                ops.save_live(rel_dir, base, payload)
                mode = "in-place"
            else:
                mode = swap(ops, rel_dir, base, tmp, bak, previous is not None)
            log(f"byte klart {item.path} via {mode}")
            try:
                live = ops.read(rel_dir, base)
            except WafBlocked:
                cfg.swapped.append(Swapped(item.path, rel_dir, base, tmp, bak, bad, mode, previous))
                raise
            live_size, live_sha = len(live), sha256(live)
            if live_size != size or live_sha != digest:
                rollback(cfg, ops, rel_dir, base, tmp, bak, bad, mode, previous)
                raise DeployError(
                    f"live sha256 {live_sha} stämmer inte efter bytet. Återställning kördes."
                )
            if mode == "in-place":
                ops.delete(rel_dir, tmp)
                log(f"raderade {tmp}")
            for leftover in (tmp, bad):
                if not isinstance(ops, TinyOps) and ops.exists(rel_dir, leftover):
                    ops.delete(rel_dir, leftover)
                    log(f"raderade {leftover}")
            if mode == "bak" and not isinstance(ops, TinyOps) and not ops.exists(rel_dir, bak):
                raise DeployError(f"{bak} saknas efter bytet")
            cfg.swapped.append(Swapped(item.path, rel_dir, base, tmp, bak, bad, mode, previous))
            return Result(item.path, size, digest, f"sha ok via {mode}, väntar på grind")
        except VerifyError as err:
            last_error = err
            retry = True
            log(f"försök {attempt} misslyckades: {err}")
        except DeployError:
            raise
        except (OSError, EOFError, *FTP_ERRORS) as err:
            if mode is not None:
                try:
                    rollback(cfg, ops, rel_dir, base, tmp, bak, bad, mode, previous)
                except Exception as roll_err:
                    raise DeployError(
                        f"fel efter byte och återställning misslyckades: {roll_err}"
                    ) from err
                raise DeployError(f"fel efter byte: {err}. Återställning kördes.") from err
            last_error = VerifyError(f"överföringen bröts: {err}")
            retry = True
            log(f"försök {attempt} misslyckades: {last_error}")
        if not retry:
            break
        try:
            delete_our_tmp(cfg, rel_dir, tmp)
        except (DeployError, WafBlocked) as cleanup_err:
            if isinstance(cleanup_err, WafBlocked):
                raise
            raise DeployError(
                f"tmp kunde inte raderas efter fel: {cleanup_err}. Live ska vara orörd."
            ) from cleanup_err
        confirm_unchanged(cfg, rel_dir, base, previous)
        if attempt >= attempts:
            break
        pause = delay_for(attempt, cfg.delays)
        if pause:
            log(f"väntar {pause:.0f}s innan nytt försök")
            time.sleep(pause)

    raise DeployError(
        f"uppladdningen av {item.path} misslyckades efter {attempts} försök: {last_error}. "
        "Live-filen rördes inte."
    )


def sweep(cfg: Config) -> None:
    """Kolla att den här körningens .bak och tmp är borta. Rör inte andra filer."""
    if not cfg.touched:
        return
    by_dir: dict[str, set[str]] = {}
    for rel_dir, base in cfg.touched:
        by_dir.setdefault(rel_dir, set()).add(base)
    leftovers = []
    for rel_dir, bases in by_dir.items():
        names = set(cfg.ops.list_names(rel_dir))
        for base in bases:
            for extra in (
                f"{base}.bak",
                f"{base}.deploy-{cfg.short}.tmp",
                f"{base}.deploy-{cfg.short}.bad.tmp",
            ):
                if extra in names:
                    leftovers.append(_display(rel_dir, extra))
    if leftovers:
        raise DeployError("rester från körningen finns kvar: " + ", ".join(sorted(set(leftovers))))
    log("rester från körningen: inga")


def remove_run_junk(cfg: Config) -> None:
    """Efter grön grind: inget skräp i mapparna som den här körningen skrev till.

    Flyttar ``*.bak`` / ``*.bak*`` till papperskorgen. Raderar ``*.uploading``
    och egna tmp-filer. Smoke-filer i samma mappar tas bort om inte
    ``keep_smoke`` är satt.
    Anropas bara när grinden är grön.
    """
    if not cfg.touched:
        return
    by_dir: dict[str, set[str]] = {}
    for rel_dir, base in cfg.touched:
        by_dir.setdefault(rel_dir, set()).add(base)
    for rel_dir, bases in by_dir.items():
        names = cfg.ops.list_names(rel_dir)
        present = set(names)
        missing = sorted(base for base in bases if base not in present)
        if missing:
            raise DeployError(
                f"listning av {rel_dir or '/'} saknar {', '.join(missing)}. Ingen vidare städning."
            )
        for name in names:
            smoke = is_smoke_name(name)
            junk = is_transfer_junk(name)
            label = _display(rel_dir, name)
            if junk:
                if _BAK_SUFFIX.search(name.lower()):
                    park_backup(cfg.ops, rel_dir, name)
                else:
                    cfg.ops.delete(rel_dir, name)
                    log(f"raderade {label}")
                continue
            if smoke and cfg.keep_smoke:
                log(f"behåller {label} (keep_smoke)")
                continue
            if smoke:
                cfg.ops.delete(rel_dir, name)
                log(f"raderade {label} (smoke)")
    log("rester från körningen: inga")


def print_plan(items: list[Item]) -> None:
    log("PLAN")
    if not items:
        log("  (inga ändringar)")
        return
    for item in items:
        if item.action == "deploy":
            log(f"  deploy        {item.path}")
        elif item.action == "skip":
            log(f"  hoppar        {item.path}  ({item.reason})")
        elif item.action == "keep-deleted":
            log(f"  raderas inte  {item.path}  ({item.reason})")
        else:
            log(f"  nekad         {item.path}  ({item.reason})")


def rollback_swapped(cfg: Config, reason: str) -> None:
    log(reason)
    if not cfg.swapped:
        log("ingen bytt fil att återställa")
        return
    ops = cfg.ops
    for swapped in reversed(cfg.swapped):
        rollback(
            cfg,
            ops,
            swapped.rel_dir,
            swapped.base,
            swapped.tmp,
            swapped.bak,
            swapped.bad,
            swapped.mode,
            swapped.previous,
        )
    cfg.swapped.clear()


def delete_kept_baks(cfg: Config) -> None:
    if not cfg.swapped:
        return
    ops = cfg.ops
    for swapped in cfg.swapped:
        names = (swapped.bak,) if isinstance(ops, TinyOps) else (swapped.bak, swapped.tmp, swapped.bad)
        for leftover in names:
            is_bak = leftover == swapped.bak
            if isinstance(ops, TinyOps):
                if swapped.mode != "bak" or not is_bak:
                    continue
                if ops.exists(swapped.rel_dir, leftover):
                    park_backup(ops, swapped.rel_dir, leftover)
                continue
            if not ops.exists(swapped.rel_dir, leftover):
                continue
            if is_bak:
                park_backup(ops, swapped.rel_dir, leftover)
                continue
            ops.delete(swapped.rel_dir, leftover)
            log(f"raderade {leftover}")


LIST_ROOT_MAX_DEPTH = 3
LIST_ROOT_MAX_ENTRIES = 200


def list_root_safe_name(name: str) -> bool:
    if not name or name in {".", ".."}:
        return False
    if "/" in name or "\\" in name or "\x00" in name:
        return False
    return True


def list_root_parse_list(line: str) -> tuple[str, str] | None:
    """Tolka en LIST-rad till (namn, dir|file|unknown)."""
    raw = line.rstrip("\r\n")
    if not raw or raw.lower().startswith("total "):
        return None
    if "<DIR>" in raw.upper():
        name = raw.split("<DIR>", 1)[-1].strip()
        if not list_root_safe_name(name):
            return None
        return name, "dir"
    parts = raw.split(None, 8)
    if len(parts) >= 9 and parts[0][:1] in {"d", "-", "l"}:
        flag = parts[0][0]
        name = parts[8]
        if flag == "l" and " -> " in name:
            name = name.split(" -> ", 1)[0].strip()
        if not list_root_safe_name(name):
            return None
        if flag == "d":
            return name, "dir"
        if flag == "-":
            return name, "file"
        return name, "unknown"
    if re.match(r"\d{2}-\d{2}-\d{2}", parts[0] if parts else ""):
        dos = raw.split(None, 3)
        if len(dos) == 4 and list_root_safe_name(dos[3].strip()):
            return dos[3].strip(), "file"
    return None


def list_root_entries(ftp: SessionFTP) -> list[tuple[str, str]]:
    """Poster i aktuell katalog: (namn, dir|file|unknown). Ingen filhämtning."""
    try:
        found: list[tuple[str, str]] = []
        for name, facts in ftp.mlsd():
            if name in {".", ".."}:
                continue
            fact_type = str(facts.get("type", "")).lower()
            if fact_type in {"cdir", "pdir"}:
                continue
            found.append((name, "dir" if fact_type == "dir" else "file"))
        return found
    except FTP_ERRORS:
        pass
    lines: list[str] = []
    try:
        ftp.dir(lines.append)
    except FTP_ERRORS:
        lines = []
    parsed = [item for item in (list_root_parse_list(line) for line in lines) if item]
    if parsed:
        return parsed
    try:
        names = ftp.nlst()
    except FTP_ERRORS:
        return []
    result: list[tuple[str, str]] = []
    for name in names:
        base = posixpath.basename(str(name).rstrip("/"))
        if list_root_safe_name(base):
            result.append((base, "unknown"))
    return result


def list_root_is_dir(ftp: SessionFTP, name: str, here: str) -> bool:
    if not list_root_safe_name(name):
        return False
    try:
        ftp.cwd(name)
    except FTP_ERRORS:
        if ftp.pwd() != here:
            ftp.cwd(here)
        return False
    ftp.cwd(here)
    return True


def list_root_child_dirs(ftp: SessionFTP) -> tuple[list[str], str]:
    entries = list_root_entries(ftp)
    note = ""
    if len(entries) > LIST_ROOT_MAX_ENTRIES:
        entries = entries[:LIST_ROOT_MAX_ENTRIES]
        note = f"klippt efter {LIST_ROOT_MAX_ENTRIES} poster"
    here = ftp.pwd()
    dirs: list[str] = []
    for name, kind in entries:
        if not list_root_safe_name(name):
            continue
        if kind == "dir":
            dirs.append(name)
        elif kind == "unknown" and list_root_is_dir(ftp, name, here):
            dirs.append(name)
    return sorted(set(dirs)), note


def list_root_climb(ftp: SessionFTP) -> str:
    """PWD, sedan CWD .. tills servern inte släpper högre upp."""
    login = ftp.pwd()
    log(f"PWD {login}")
    seen = {login}
    for _ in range(32):
        current = ftp.pwd()
        try:
            ftp.cwd("..")
        except FTP_ERRORS:
            break
        nxt = ftp.pwd()
        if nxt == current or nxt in seen:
            break
        seen.add(nxt)
        log(f"upp {nxt}")
    top = ftp.pwd()
    log(f"högsta {top}")
    return top


def list_root_walk(ftp: SessionFTP, depth: int) -> None:
    """Skriv katalognamn nedåt, högst LIST_ROOT_MAX_DEPTH steg från högsta."""
    here = ftp.pwd()
    log(here)
    if depth >= LIST_ROOT_MAX_DEPTH:
        return
    children, note = list_root_child_dirs(ftp)
    if ftp.pwd() != here:
        ftp.cwd(here)
    if note:
        log(note)
    for name in children:
        try:
            ftp.cwd(name)
        except FTP_ERRORS:
            log(f"kunde inte öppna {name}")
            if ftp.pwd() != here:
                ftp.cwd(here)
            continue
        try:
            list_root_walk(ftp, depth + 1)
        finally:
            ftp.cwd(here)


def run_list_root(cfg: Config) -> int:
    """Logga in och skriv katalognamn. Öppnar ingen annan transport."""
    if cfg.transport not in {"ftp", "auto"}:
        raise DeployError("list-root kräver transport ftp eller auto. Ingen anslutning gjordes.")
    missing = missing_ftp_secrets(cfg)
    if missing:
        raise DeployError(
            "list-root saknar FTP-secrets: " + ", ".join(missing) + ". Ingen anslutning gjordes."
        )
    if cfg.dry_run:
        log("list-root torrkörning: skulle logga in, köra PWD och lista katalognamn. Ingen anslutning görs.")
        return 0
    ftp: SessionFTP | None = None
    try:
        ftp = connect(cfg)
        list_root_climb(ftp)
        list_root_walk(ftp, 0)
        log("list-root klar")
        return 0
    except DeployError:
        raise
    except (OSError, EOFError, *FTP_ERRORS) as err:
        raise DeployError(f"list-root: FTP-läsning misslyckades ({err})") from err
    finally:
        close_ftp(ftp)


SERVICE_REDIRECT_DIR = "/service.jarfalla.example.test"
SERVICE_REDIRECT_PUBLIC = "/service.jarfalla.example.test/public_html"
SERVICE_REDIRECT_FILE = ".htaccess"
SERVICE_REDIRECT_BODY = (
    b"RewriteEngine On\nRewriteRule ^ https://www.jarfalla.example.test/ [R=301,L]\n"
)
SERVICE_REDIRECT_DIGESTS = (
    ("XSHA256", "sha256"),
    ("XSHA1", "sha1"),
    ("XSHA", "sha1"),
    ("MD5", "md5"),
    ("XMD5", "md5"),
)


def service_redirect_payload() -> bytes:
    """Läs den hårdkodade redirect-filen. Avvikelse stoppar innan någon anslutning."""
    path = os.path.join(ROOT, "deploy", "service-redirect.htaccess")
    try:
        with open(path, "rb") as handle:
            data = handle.read()
    except OSError as err:
        raise DeployError(
            "service-redirect: kan inte läsa deploy/service-redirect.htaccess. Ingen anslutning gjordes."
        ) from err
    if data != SERVICE_REDIRECT_BODY:
        raise DeployError(
            "service-redirect: deploy/service-redirect.htaccess matchar inte det hårdkodade innehållet. "
            "Ingen anslutning gjordes."
        )
    return data


def service_redirect_ensure_dir(ftp: SessionFTP, path: str) -> None:
    """MKD en av de två fasta katalogerna om CWD misslyckas."""
    if path not in {SERVICE_REDIRECT_DIR, SERVICE_REDIRECT_PUBLIC}:
        raise DeployError("service-redirect: otillåten katalog. Inget skapades.")
    try:
        ftp.cwd(path)
    except FTP_ERRORS:
        if ftp.pwd() != "/":
            ftp.cwd("/")
        if ftp.pwd() != "/":
            raise DeployError("service-redirect: inte i /. Inget skapades.")
        ftp.mkd(path)
        log(f"MKD {path}")
        ftp.cwd(path)
    else:
        log(f"finns {path}")
    if ftp.pwd() != path:
        raise DeployError(f"service-redirect: hamnade i {ftp.pwd()} i stället för {path}. Ingen fil skrevs.")


def service_redirect_names(ftp: SessionFTP) -> list[str]:
    """Bara basnamn i den fasta public_html-katalogen."""
    if ftp.pwd() != SERVICE_REDIRECT_PUBLIC:
        raise DeployError("service-redirect: listar bara den fasta public_html-katalogen.")
    try:
        raw = ftp.nlst()
    except FTP_ERRORS as err:
        raise DeployError(f"service-redirect: listning misslyckades ({err})") from err
    names: list[str] = []
    for item in raw:
        base = posixpath.basename(str(item).rstrip("/"))
        if base and base not in {".", ".."}:
            names.append(base)
    return sorted(set(names))


def service_redirect_hex(reply: str, length: int) -> str | None:
    match = re.search(rf"\b([0-9a-fA-F]{{{length}}})\b", reply)
    if not match:
        return None
    return match.group(1).lower()


def service_redirect_digest_match(ftp: SessionFTP, payload: bytes) -> bool | None:
    """True om en hash matchar, False om en hash skiljer sig, None om servern saknar hash.

    Jämförelsen hämtar inte filen. Argumentet är bara det fasta filnamnet.
    """
    name = SERVICE_REDIRECT_FILE
    if name != ".htaccess":
        raise DeployError("service-redirect: otillåtet filnamn.")
    if ftp.pwd() != SERVICE_REDIRECT_PUBLIC:
        raise DeployError("service-redirect: fel katalog vid jämförelse. Ingen överskrivning.")
    lengths = {"sha256": 64, "sha1": 40, "md5": 32}
    for command, algo in SERVICE_REDIRECT_DIGESTS:
        if command not in {"XSHA256", "XSHA1", "XSHA", "MD5", "XMD5"}:
            raise DeployError("service-redirect: otillåtet kommando.")
        try:
            reply = ftp.voidcmd(f"{command} {name}")
        except FTP_ERRORS as err:
            text = str(err)
            if text.startswith("500") or text.startswith("502") or text.startswith("504"):
                continue
            raise DeployError(
                f"service-redirect: hash-kommando misslyckades ({text}). Ingen överskrivning."
            ) from err
        remote = service_redirect_hex(reply, lengths[algo])
        if remote is None:
            continue
        local = hashlib.new(algo, payload).hexdigest()
        return remote == local
    return None


def service_redirect_existing_size(ftp: SessionFTP) -> int | None:
    """Storlek om filen finns. None om den saknas. Skriver inte."""
    restore_binary(ftp)
    size: int | None
    try:
        size = ftp.size(SERVICE_REDIRECT_FILE)
    except FTP_ERRORS:
        size = None
    if size is not None:
        return int(size)
    names = service_redirect_names(ftp)
    if SERVICE_REDIRECT_FILE in names:
        raise DeployError(
            "service-redirect: .htaccess finns men storlek gick inte att läsa. Ingen överskrivning."
        )
    return None


def service_redirect_is_different(ftp: SessionFTP, payload: bytes, size: int) -> bool:
    matched = service_redirect_digest_match(ftp, payload)
    if matched is True:
        return size != len(payload)
    if matched is False:
        return True
    if size != len(payload):
        return True
    raise DeployError(
        "service-redirect: kan inte jämföra .htaccess utan hash från servern "
        "(XSHA256, XSHA1, XSHA, MD5, XMD5). Ingen överskrivning."
    )


def service_redirect_report(ftp: SessionFTP) -> None:
    if ftp.pwd() != SERVICE_REDIRECT_PUBLIC:
        ftp.cwd(SERVICE_REDIRECT_PUBLIC)
    if ftp.pwd() != SERVICE_REDIRECT_PUBLIC:
        raise DeployError("service-redirect: kan inte lista den fasta katalogen.")
    names = service_redirect_names(ftp)
    shown = ", ".join(names) if names else "(tom)"
    log(f"LIST {SERVICE_REDIRECT_PUBLIC} {shown}")
    restore_binary(ftp)
    try:
        size = ftp.size(SERVICE_REDIRECT_FILE)
    except FTP_ERRORS as err:
        raise DeployError(f"service-redirect: storlek gick inte att läsa ({err})") from err
    log(f"SIZE {SERVICE_REDIRECT_PUBLIC}/{SERVICE_REDIRECT_FILE} bytes={size}")


def service_redirect_apply(ftp: SessionFTP, payload: bytes, blocksize: int) -> None:
    """CWD till /, MKD de två katalogerna om de saknas, STOR eller jämför."""
    top = list_root_climb(ftp)
    if top != "/":
        raise DeployError(
            f"service-redirect nådde inte FTP-rot /. Högsta var {top}. Inget skapades."
        )
    ftp.cwd("/")
    if ftp.pwd() != "/":
        raise DeployError("service-redirect är inte i /. Inget skapades.")
    service_redirect_ensure_dir(ftp, SERVICE_REDIRECT_DIR)
    service_redirect_ensure_dir(ftp, SERVICE_REDIRECT_PUBLIC)
    if ftp.pwd() != SERVICE_REDIRECT_PUBLIC:
        raise DeployError("service-redirect: fel katalog. Ingen fil skrevs.")
    existing = service_redirect_existing_size(ftp)
    if existing is None:
        ftp.storbinary(f"STOR {SERVICE_REDIRECT_FILE}", io.BytesIO(payload), blocksize=blocksize)
        log(f"skrev {SERVICE_REDIRECT_PUBLIC}/{SERVICE_REDIRECT_FILE}")
        restore_binary(ftp)
        try:
            written = ftp.size(SERVICE_REDIRECT_FILE)
        except FTP_ERRORS as err:
            raise DeployError(
                f"service-redirect: uppladdningen gick inte att mäta ({err}). Ingen överskrivning görs."
            ) from err
        if written != len(payload):
            raise DeployError(
                f"service-redirect: storlek {written} skiljer sig från {len(payload)}. Ingen överskrivning görs."
            )
    elif service_redirect_is_different(ftp, payload, existing):
        service_redirect_report(ftp)
        raise DeployError(
            f"service-redirect: {SERVICE_REDIRECT_PUBLIC}/{SERVICE_REDIRECT_FILE} "
            "har annat innehåll. Ingen överskrivning."
        )
    else:
        log(f"identisk {SERVICE_REDIRECT_PUBLIC}/{SERVICE_REDIRECT_FILE}. Ingen ändring.")
    service_redirect_report(ftp)


def run_service_redirect(cfg: Config) -> int:
    """Skapa redirect-katalogen och en fil. Öppnar inte TinyFM."""
    if cfg.transport not in {"ftp", "auto"}:
        raise DeployError("service-redirect kräver transport ftp eller auto. Ingen anslutning gjordes.")
    missing = missing_ftp_secrets(cfg)
    if missing:
        raise DeployError(
            "service-redirect saknar FTP-secrets: " + ", ".join(missing) + ". Ingen anslutning gjordes."
        )
    payload = service_redirect_payload()
    if cfg.dry_run:
        log("service-redirect torrkörning: CWD /")
        log(f"service-redirect torrkörning: MKD {SERVICE_REDIRECT_DIR} om den saknas")
        log(f"service-redirect torrkörning: MKD {SERVICE_REDIRECT_PUBLIC} om den saknas")
        log(
            f"service-redirect torrkörning: STOR {SERVICE_REDIRECT_PUBLIC}/{SERVICE_REDIRECT_FILE} "
            f"om den saknas  bytes={len(payload)}  sha256={sha256(payload)}"
        )
        log("service-redirect torrkörning: identiskt innehåll lämnas. Annat innehåll avbryter utan överskrivning.")
        log("service-redirect torrkörning: ingen anslutning, ingen MKD, ingen STOR.")
        return 0
    ftp: SessionFTP | None = None
    try:
        ftp = connect(cfg)
        service_redirect_apply(ftp, payload, cfg.blocksize)
        log("service-redirect klar")
        return 0
    except DeployError:
        raise
    except (OSError, EOFError, *FTP_ERRORS) as err:
        raise DeployError(f"service-redirect: FTP misslyckades ({err}). Ingen ytterligare ändring görs.") from err
    finally:
        close_ftp(ftp)


def names_in_dir(ftp: SessionFTP) -> list[str]:
    lines: list[str] = []
    try:
        ftp.retrlines("NLST", lines.append)
    except FTP_ERRORS:
        return []
    finally:
        restore_binary(ftp)
    return sorted({posixpath.basename(line.rstrip("/")) for line in lines if line and line not in {".", ".."}})


def run_check(cfg: Config) -> int:
    if not cfg.paths:
        raise DeployError("check kräver --paths, t.ex. src/sms.php,index.php,src/ordrar.php")
    paths = [safe_rel(path) for path in cfg.paths]
    for path in paths:
        reason = deny_reason(path)
        if reason:
            raise DeployError(f"{path} får inte läsas den här vägen ({reason})")
    if cfg.dry_run:
        for path in paths:
            log(f"SKULLE jämföra live {path} mot {cfg.commit[:12]}")
        log("Torrkörning klar. Ingen anslutning görs.")
        return 0
    if isinstance(cfg.ops, TinyOps):
        mismatches = 0
        for path in paths:
            rel_dir = posixpath.dirname(path)
            base = posixpath.basename(path)
            try:
                data = cfg.ops.read(rel_dir, base)
            except MissingFile:
                mismatches += 1
                log(f"SAKNAS {path}  ingen listning")
                continue
            digest = sha256(data)
            if git_ok(cfg.repo, "cat-file", "-e", f"{cfg.commit}:{path}"):
                expected = blob(cfg.repo, cfg.commit, path)
                same = data == expected
                if not same:
                    mismatches += 1
                relation = "matchar main" if same else "SKILJER sig från main"
            else:
                mismatches += 1
                relation = "finns inte i committen"
            bak = "ja" if cfg.ops.exists(rel_dir, f"{base}.bak") else "nej"
            log(f"LIVE {path}  bytes={len(data)}  sha256={digest}  {relation}  bak={bak}")
        if mismatches:
            raise DeployError(f"check: {mismatches} fil(er) matchar inte committen")
        log("check: alla angivna filer matchar committen")
        return 0
    require_secrets(cfg)
    ftp = connect(cfg)
    mismatches = 0
    try:
        seen_dirs: set[str] = set()
        for path in paths:
            rel_dir = posixpath.dirname(path)
            base = posixpath.basename(path)
            try:
                enter_existing_dir(ftp, cfg.root, rel_dir)
            except DeployError:
                mismatches += 1
                log(f"SAKNAS {path}  katalog finns inte")
                seen_dirs.add(rel_dir)
                continue
            if rel_dir not in seen_dirs:
                listing = ", ".join(names_in_dir(ftp)) or "(tom)"
                log(f"LIST {rel_dir or '/'}  {listing}")
                seen_dirs.add(rel_dir)
            bak_exists = remote_exists(ftp, f"{base}.bak")
            if not remote_exists(ftp, base):
                mismatches += 1
                log(f"SAKNAS {path}  bak={'ja' if bak_exists else 'nej'}")
                continue
            data = retr_bytes(ftp, base, cfg.blocksize)
            digest = sha256(data)
            if git_ok(cfg.repo, "cat-file", "-e", f"{cfg.commit}:{path}"):
                expected = blob(cfg.repo, cfg.commit, path)
                same = data == expected
                if not same:
                    mismatches += 1
                relation = "matchar main" if same else "SKILJER sig från main"
            else:
                mismatches += 1
                relation = "finns inte i committen"
            log(
                f"LIVE {path}  bytes={len(data)}  sha256={digest}  {relation}  "
                f"bak={'ja' if bak_exists else 'nej'}"
            )
    finally:
        close_ftp(ftp)
    if mismatches:
        raise DeployError(f"check: {mismatches} fil(er) matchar inte committen")
    log("check: alla angivna filer matchar committen")
    return 0


def run_cleanup_bak(cfg: Config) -> int:
    """Radera gamla .bak för --paths. Byt ingen live-fil och anropa inte grinden."""
    if not cfg.paths:
        raise DeployError("cleanup-bak kräver --paths med filen vars .bak ska bort")
    paths = [safe_rel(path) for path in cfg.paths]
    stems: list[tuple[str, str]] = []
    for path in paths:
        reason = deny_reason(path)
        if reason:
            raise DeployError(f"{path} får inte städas ({reason})")
        rel_dir = posixpath.dirname(path)
        stem = live_stem_for_cleanup(path)
        stems.append((rel_dir, stem))
    if cfg.dry_run:
        for rel_dir, stem in stems:
            shown = _display(rel_dir, stem)
            log(f"SKULLE radera {shown}.bak och {stem}.bak*")
        log("Torrkörning klar. Ingen anslutning görs. Ingen live-fil byts.")
        return 0
    ops = cfg.ops
    results: list[Result] = []
    for rel_dir, stem in stems:
        shown = _display(rel_dir, stem)
        before = ops.read_or_none(rel_dir, stem)
        removed = clear_stale_baks(ops, rel_dir, stem)
        after = ops.read_or_none(rel_dir, stem)
        if before != after:
            raise DeployError(f"cleanup-bak ändrade live-filen {shown}")
        if removed:
            log(f"städade {shown}: {', '.join(removed)}. Live byttes inte.")
            outcome = "bak borta, live orörd" if before is not None else "bak borta, live saknades"
        else:
            log(f"ingen gammal backup för {shown}")
            outcome = "ingen bak, live orörd" if before is not None else "ingen bak, live saknades"
        digest = sha256(before) if before is not None else ""
        results.append(Result(shown, len(before or b""), digest, outcome))
    print_summary(cfg, [], results)
    return 0


def run_rollback(cfg: Config) -> int:
    if not cfg.paths:
        raise DeployError("rollback kräver --paths med den fil vars .bak ska tillbaka")
    paths = [safe_rel(path) for path in cfg.paths]
    for path in paths:
        reason = deny_reason(path)
        if reason:
            raise DeployError(f"{path} får inte återställas ({reason})")
    if cfg.dry_run:
        for path in paths:
            log(f"SKULLE lägga tillbaka {path}.bak till {path}")
        log("Torrkörning klar. Ingen anslutning görs.")
        return 0
    ops = cfg.ops
    results: list[Result] = []
    for path in paths:
        rel_dir = posixpath.dirname(path)
        base = posixpath.basename(path)
        bak = f"{base}.bak"
        bad = f"{base}.deploy-{cfg.short}.bad.tmp"
        tmp = f"{base}.deploy-{cfg.short}.tmp"
        try:
            previous = ops.read(rel_dir, bak)
        except MissingFile as err:
            raise DeployError(f"{path}.bak finns inte. Live-filen rördes inte.") from err
        rollback(cfg, ops, rel_dir, base, tmp, bak, bad, "bak", previous)
        if ops.exists(rel_dir, bak):
            raise DeployError(f"{path}.bak finns kvar efter rollback")
        results.append(Result(path, len(previous), sha256(previous), "återställd från .bak"))
    print_summary(cfg, [], results)
    return 0


def print_summary(cfg: Config, items: list[Item], results: list[Result]) -> None:
    log("=== Sammanfattning ===")
    log(f"commit  {cfg.commit}")
    log(f"läge    {cfg.mode}{' torrkörning' if cfg.dry_run else ''}  transport {cfg.transport}")
    if results:
        for result in results:
            log(
                f"{result.outcome:16} {result.path}  bytes={result.size}  sha256={result.sha}"
            )
    else:
        log("(inga filer uppladdade)")
    deleted = [item.path for item in items if item.action == "keep-deleted"]
    if deleted:
        log("borttagna i git, kvar på servern:")
        for path in deleted:
            log(f"  {path}")
    else:
        log("borttagna i git, kvar på servern: inga")
    announce_dry_run(cfg)


def load_config(args: argparse.Namespace) -> Config:
    delays = [float(part) for part in os.environ.get("JEAB_DEPLOY_RETRY_DELAYS", "5,15,30").split(",") if part.strip()]
    gate = args.gate_url or os.environ.get("JEAB_DEPLOY_GATE_URL", DEFAULT_GATE)
    host, user, password, port = read_loopia_ftp_env()
    tiny_user = os.environ.get("TINYFM_USER", "").strip()
    tiny_pass = os.environ.get("TINYFM_PASS", "").strip()
    campaign = os.environ.get("JEAB_GATE_CODE", "").strip()
    gate_id = os.environ.get("JEAB_GATE_ID", "").strip()
    if host and host not in gate:
        remember_secret(host)
    remember_secret(user)
    remember_secret(password)
    remember_secret(tiny_user)
    remember_secret(tiny_pass)
    remember_secret(campaign)
    remember_secret(gate_id)
    paths: list[str] = []
    for raw in args.paths or []:
        paths.extend(split_paths(raw))
    return Config(
        repo=args.repo,
        commit="",
        base=args.base or "",
        paths=paths,
        dry_run=bool(args.dry_run),
        from_diff=bool(args.from_diff),
        mode=args.mode,
        insecure=bool(args.insecure),
        require_main=bool(args.require_main),
        allow_any_commit=bool(args.allow_any_commit),
        host=host,
        user=user,
        password=password,
        root=os.environ.get("LOOPIA_FTP_ROOT", DEFAULT_ROOT).strip() or DEFAULT_ROOT,
        port=port,
        gate_url=gate,
        needle=args.needle or os.environ.get("JEAB_DEPLOY_GATE_NEEDLE", DEFAULT_NEEDLE),
        attempts=max(1, int(os.environ.get("JEAB_DEPLOY_ATTEMPTS", "3"))),
        delays=delays,
        timeout=float(os.environ.get("JEAB_DEPLOY_TIMEOUT", "180")),
        http_timeout=float(os.environ.get("JEAB_DEPLOY_HTTP_TIMEOUT", "30")),
        blocksize=max(1, int(os.environ.get("JEAB_DEPLOY_BLOCKSIZE", "65536"))),
        transport=args.transport,
        ftp_probe_timeout=float(os.environ.get("JEAB_DEPLOY_FTP_PROBE_TIMEOUT", "8")),
        tinyfm_url=(os.environ.get("JEAB_DEPLOY_TINYFM_URL") or "https://flowstack.example.test/filer.php").strip(),
        tinyfm_user=tiny_user,
        tinyfm_pass=tiny_pass,
        campaign=campaign,
        gate_id=gate_id,
        tinyfm_gap=float(os.environ.get("JEAB_DEPLOY_TINYFM_GAP", "4")),
        keep_smoke=bool(args.keep_smoke) or truthy(os.environ.get("JEAB_DEPLOY_KEEP_SMOKE", "")),
        maintenance=bool(getattr(args, "maintenance", False)) or truthy(os.environ.get("JEAB_DEPLOY_MAINTENANCE", "")),
        since_sha=(args.since_sha or "").strip(),
    )


def require_secrets(cfg: Config) -> None:
    missing = []
    if not cfg.host:
        missing.append("LOOPIA_FTP_HOST")
    if not cfg.user:
        missing.append("LOOPIA_FTP_USER")
    if not cfg.password:
        missing.append("LOOPIA_FTP_PASS")
    if missing:
        raise DeployError(
            "Saknar secrets: " + ", ".join(missing) + ". Inga värden skrivs ut."
        )


def set_deploy_flag(cfg: Config) -> None:
    """Sätt underhåll. Skriver över flaggan. Raderar den inte."""
    stamp = str(int(time.time())).encode("ascii") + b"\n"
    log(f"underhåll på: {DEPLOY_FLAG_REL}")
    cfg.ops.write_flag(stamp)


def clear_deploy_flag(cfg: Config) -> None:
    """Töm flaggan. Filen ligger kvar. Tomt innehåll är av."""
    log(f"underhåll av: {DEPLOY_FLAG_REL} töms")
    cfg.ops.write_flag(b"")


def run_deploy(cfg: Config) -> int:
    if cfg.paths:
        log(f"commit {cfg.commit}  urval: {', '.join(cfg.paths)}")
        items = plan_explicit(cfg)
    elif cfg.dry_run or cfg.from_diff:
        if cfg.from_diff:
            prepare_from_diff(cfg)
        elif not cfg.base:
            cfg.base = parent_commit(cfg.repo, cfg.commit)
        else:
            cfg.base = resolve_commit(cfg.repo, cfg.base)
        log(f"commit {cfg.commit}  bas {cfg.base}")
        items = plan_diff(cfg)
    else:
        raise DeployError(
            "deploy kräver --paths, t.ex. src/sms.php,index.php,src/ordrar.php"
        )
    print_plan(items)
    blocked = [item for item in items if item.action in {"deny", "missing"}]
    if blocked:
        print_summary(cfg, items, [])
        raise DeployError("utpekad sökväg får inte deployas")
    pending = [item for item in items if item.action == "deploy"]
    by_path = {item.path: item for item in pending}
    deploys = [by_path[path] for path in order_deploy_paths(cfg.repo, [item.path for item in pending])]
    warn_missing_runtime(cfg, {item.path for item in deploys})
    if cfg.dry_run:
        if cfg.maintenance:
            log(f"SKULLE sätta {DEPLOY_FLAG_REL} och tömma den efter uppladdningen")
        for rel in PRIVATE_HTACCESS_FILES:
            if private_htaccess_in_commit(cfg, rel):
                log(f"SKULLE lägga {rel} om den saknas eller inte spärrar")
        for item in deploys:
            payload = blob(cfg.repo, cfg.commit, item.path)
            log(
                f"SKULLE deploya {item.path}  bytes={len(payload)}  sha256={sha256(payload)}"
            )
        log("Torrkörning klar. FTP kontaktas inte.")
        print_summary(cfg, items, [])
        return 0
    if not deploys:
        log("Inget att ladda upp.")
        print_summary(cfg, items, [])
        return 0
    if not cfg.dry_run and getattr(cfg, "ops", None) is None:
        cfg.ops = open_transport(cfg)
    results: list[Result] = []
    armed = False
    deploy_error: DeployError | None = None
    try:
        if cfg.maintenance:
            set_deploy_flag(cfg)
            armed = True
        deploys = apply_private_htaccess(cfg, deploys)
        for item in deploys:
            results.append(deploy_one(cfg, item))
        verify_live_against_commit(cfg, deploys)
        if armed:
            clear_deploy_flag(cfg)
            armed = False
    except WafBlocked as err:
        if cfg.swapped:
            log("WAF under TinyFM. Redan bytta filer lämnas. .bak lämnas kvar. Ingen rollback och inga fler anrop.")
        else:
            log("WAF före byte. Live-filen rördes inte. Inga fler TinyFM-anrop.")
        print_summary(cfg, items, results)
        deploy_error = DeployError(str(err))
    except DeployError as err:
        try:
            rollback_swapped(cfg, "Batchen avbröts före grinden. Redan bytta filer återställs från .bak.")
        except DeployError as roll_err:
            log(f"återställning efter avbrott misslyckades: {roll_err}")
        print_summary(cfg, items, results)
        deploy_error = err
    finally:
        if armed:
            try:
                clear_deploy_flag(cfg)
            except (DeployError, WafBlocked) as err:
                log(f"kunde inte tömma underhållsflaggan: {err}. Den släpper av sig själv efter 3 minuter.")
            armed = False
    if deploy_error is not None:
        raise deploy_error
    kind, detail = read_gate(cfg)
    log(detail)
    if kind == "green":
        delete_kept_baks(cfg)
        remove_run_junk(cfg)
        print_summary(cfg, items, results)
        return 0
    if kind == "rollback":
        try:
            set_deploy_flag(cfg)
            rollback_swapped(cfg, "Grinden kräver rollback från .bak.")
        except DeployError as err:
            log(f"rollback misslyckades: {err}")
            print_summary(cfg, items, results)
            raise
        finally:
            try:
                clear_deploy_flag(cfg)
            except (DeployError, WafBlocked) as err:
                log(f"kunde inte tömma underhållsflaggan: {err}. Den släpper av sig själv efter 3 minuter.")
        try:
            sweep(cfg)
        except DeployError as err:
            log(f"Varning vid restkontroll: {err}")
        print_summary(cfg, items, results)
        raise DeployError(detail)
    kept = [swapped.path + ".bak" for swapped in cfg.swapped if swapped.mode == "bak"]
    if kept:
        log("kvar på servern: " + ", ".join(kept))
    print_summary(cfg, items, results)
    raise DeployError(detail)


def run_clear_flag(cfg: Config) -> int:
    """Töm underhållsflaggan. Torrkörning ansluter inte."""
    if cfg.dry_run:
        log(f"SKULLE tömma {DEPLOY_FLAG_REL}. Torrkörning ansluter inte.")
        return 0
    if getattr(cfg, "ops", None) is None:
        cfg.ops = open_transport(cfg)
    clear_deploy_flag(cfg)
    return 0


def guard_before_connect(cfg: Config) -> None:
    """Neka och kräv fillista innan någon FTP- eller TinyFM-anslutning."""
    if cfg.mode == "list-root" or cfg.mode == "service-redirect" or cfg.mode == "clear-flag":
        return
    if cfg.mode == "deploy":
        if not cfg.dry_run and not cfg.paths and not cfg.from_diff:
            raise DeployError(
                "deploy kräver --paths, t.ex. src/sms.php,index.php,src/ordrar.php"
            )
        return
    if not cfg.paths:
        if cfg.mode == "check":
            raise DeployError("check kräver --paths, t.ex. src/sms.php,index.php,src/ordrar.php")
        if cfg.mode == "cleanup-bak":
            raise DeployError("cleanup-bak kräver --paths med filen vars .bak ska bort")
        raise DeployError("rollback kräver --paths med den fil vars .bak ska tillbaka")
    for raw in cfg.paths:
        path = safe_rel(raw)
        reason = deny_reason(path)
        if not reason:
            continue
        if cfg.mode == "check":
            raise DeployError(f"{path} får inte läsas den här vägen ({reason})")
        if cfg.mode == "cleanup-bak":
            raise DeployError(f"{path} får inte städas ({reason})")
        raise DeployError(f"{path} får inte återställas ({reason})")


def run(cfg: Config) -> int:
    cfg.commit = resolve_commit(cfg.repo, cfg.commit or "HEAD")
    cfg.short = cfg.commit[:12]
    ensure_on_main(cfg)
    guard_before_connect(cfg)
    cfg.ops = None
    try:
        if cfg.mode == "check":
            if not cfg.dry_run:
                cfg.ops = open_transport(cfg)
            return run_check(cfg)
        if cfg.mode == "rollback":
            if not cfg.dry_run:
                cfg.ops = open_transport(cfg)
            return run_rollback(cfg)
        if cfg.mode == "cleanup-bak":
            if not cfg.dry_run:
                cfg.ops = open_transport(cfg)
            return run_cleanup_bak(cfg)
        if cfg.mode == "list-root":
            return run_list_root(cfg)
        if cfg.mode == "service-redirect":
            return run_service_redirect(cfg)
        if cfg.mode == "clear-flag":
            return run_clear_flag(cfg)
        if cfg.mode != "deploy":
            raise DeployError(f"okänt läge {cfg.mode}")
        return run_deploy(cfg)
    finally:
        ops = getattr(cfg, "ops", None)
        if ops is not None:
            ops.close()


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Säker FTPES-deploy av ändrade filer till Loopia.")
    parser.add_argument("--repo", default=ROOT)
    parser.add_argument("--commit", default="HEAD")
    parser.add_argument(
        "--mode",
        choices=("deploy", "check", "rollback", "cleanup-bak", "list-root", "service-redirect", "clear-flag"),
        default="deploy",
        help=(
            "deploy, check (läs sha), rollback (.bak tillbaka), cleanup-bak (radera gammal .bak utan byte), "
            "list-root (bara katalognamn via FTP), service-redirect (hårdkodad redirect via FTP) "
            "eller clear-flag (töm underhållsflaggan)."
        ),
    )
    parser.add_argument(
        "--transport",
        choices=("auto", "ftp", "tinyfm"),
        default="auto",
        help="auto provar FTP och växlar till tinyfm. ftp stoppar om kontrollen faller.",
    )
    parser.add_argument(
        "--base",
        default="",
        help="Jämför mot den här refen. Tomt = föräldern vid torrkörning. Push skickar before.",
    )
    parser.add_argument(
        "--from-diff",
        action="store_true",
        help="Deploya git diff --base..commit. Hoppar över spärrade sökvägar. Kräver --base.",
    )
    parser.add_argument(
        "--paths",
        action="append",
        default=[],
        help="Deploya bara dessa sökvägar från committen. Kommaseparerat eller upprepat.",
    )
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument(
        "--since-sha",
        default="",
        help="Valfri. Varning om körfiler ändrade sedan den här SHA saknas i paths.",
    )
    parser.add_argument("--insecure", action="store_true", help="Lita inte på certifikatet. Bara för lokal övning.")
    parser.add_argument("--require-main", action="store_true")
    parser.add_argument("--allow-any-commit", action="store_true")
    parser.add_argument("--gate-url", default="")
    parser.add_argument("--needle", default="")
    parser.add_argument(
        "--keep-smoke",
        action="store_true",
        help="Behåll smoke-filer (deploy-check.txt, smoke, smoke.txt) efter grön grind. Standard är att städa bort dem.",
    )
    parser.add_argument(
        "--maintenance",
        action="store_true",
        help="Visa underhållssidan under uppladdningen. Standard är av, så en vanlig deploy inte lägger upp sidan.",
    )
    return parser


def main(argv: list[str] | None = None) -> int:
    reset_secrets()
    args = build_parser().parse_args(argv)
    cfg = load_config(args)
    cfg.commit = args.commit
    try:
        return run(cfg)
    except DeployError as err:
        log(f"FEL: {err}")
        return 1
    except WafBlocked as err:
        log(f"FEL: {err}")
        return 1
    except Exception as err:
        log(f"FEL: {type(err).__name__}: {err}")
        return 1


if __name__ == "__main__":
    sys.exit(main())
