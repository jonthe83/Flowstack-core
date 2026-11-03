#!/usr/bin/env python3
"""Hämta tre kalkylträd från Loopia. Bara läsning.

Samma FTPS-transport som ``loopia_deploy.connect``: ``SessionFTP`` med
återanvänd TLS-session, ``LOOPIA_FTP_HOST``, ``LOOPIA_FTP_USER`` och
``LOOPIA_FTP_PASS``. Rötterna är konstanter. Inget argument kan byta dem.

Filer skrivs bara till den lokala katalogen ``--output``. Kända hemligheter
i text maskeras till ``***MASKED***``. En gitleaks-liknande kontroll får
jobbet att falla om något sådant värde finns kvar.
"""

from __future__ import annotations

import argparse
import os
import posixpath
import re
import shutil
import ssl
import sys
from dataclasses import dataclass, field
from pathlib import Path

_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
if _SCRIPT_DIR not in sys.path:
    sys.path.insert(0, _SCRIPT_DIR)

from loopia_deploy import (  # noqa: E402
    FTP_ERRORS,
    SessionFTP,
    close_ftp,
    list_root_entries,
    list_root_safe_name,
    retr_bytes,
)

MASK = "***MASKED***"
API_ROOT = "/api.kalkyl.jarfalla.example.test/public_html"
ROOTS = (
    API_ROOT,
    "/kalkyl.jarfalla.example.test/public_html",
    "/kalkyl.malmo.example.test/public_html",
)

READ_VERBS = frozenset(
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
        "SIZE",
        "STAT",
        "STRU",
        "SYST",
        "TYPE",
        "USER",
        "XCWD",
        "XPWD",
    }
)

_KEYWORD = re.compile(
    r"(?i)(?:"
    r"api[_\s-]?keys?|apikeys?|"
    r"access[_\s-]?tokens?|refresh[_\s-]?tokens?|auth[_\s-]?tokens?|"
    r"(?<![A-Za-z0-9])tokens?(?![A-Za-z0-9])|"
    r"client[_\s-]?secrets?|service[_\s-]?roles?|"
    r"passwords?|passwds?|"
    r"(?<![A-Za-z0-9])secrets?(?![A-Za-z0-9])|"
    r"database[_\s-]?urls?|private[_\s-]?keys?|"
    r"(?<![A-Za-z0-9])dsn(?![A-Za-z0-9])"
    r")"
)

_FORMAT_RULES: tuple[tuple[str, re.Pattern[str], str], ...] = (
    (
        "private-key",
        re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]+?-----END [A-Z ]*PRIVATE KEY-----"),
        MASK,
    ),
    (
        "google-api-key",
        re.compile(r"AIza[0-9A-Za-z\-_]{35}"),
        MASK,
    ),
    (
        "supabase-jwt",
        re.compile(r"eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}"),
        MASK,
    ),
    (
        "supabase-secret",
        re.compile(r"sb_(?:secret|publishable)_[A-Za-z0-9_-]{20,}"),
        MASK,
    ),
    (
        "aws-access-token",
        re.compile(r"\bAKIA[0-9A-Z]{16}\b"),
        MASK,
    ),
    (
        "uri",
        re.compile(
            r"(?i)\b(?:postgres(?:ql)?|mysql|mariadb|mongodb(?:\+srv)?|redis|rediss|amqp|amqps)://[^\s'\"<>]+"
        ),
        MASK,
    ),
    (
        "bearer",
        re.compile(r"(?i)\bBearer\s+[A-Za-z0-9\-._~+/]{8,}=*"),
        "Bearer " + MASK,
    ),
)

_QUERY_SECRET = re.compile(
    r"(?i)((?:api[_-]?key|access[_-]?token|auth[_-]?token|client[_-]?secret|password|pwd)=)"
    r"(?!\*\*\*MASKED\*\*\*)([^&\s'\"<>()]{4,})"
)

_ASSIGN = re.compile(
    r"""(?ix)
    (?P<prefix>
        '(?:\\'|[^']){0,80}'\s*=>\s*
      | "(?:\\"|[^"]){0,80}"\s*=>\s*
      | (?:(?:const|let|var)\s+)?
        \$?
        (?:[A-Za-z_][A-Za-z0-9_]*\s*(?:->|::|\.)\s*)*
        [A-Za-z_][A-Za-z0-9_]*
        \s*(?:===|==|[:=])\s*
    )
    (?P<quote>['\"`])
    (?P<value>(?:\\.|(?!(?P=quote)).)+)
    (?P=quote)
    """
)

_DEFINE = re.compile(
    r"""(?ix)
    (?P<prefix>\bdefine\s*\(\s*(['\"])[^'\"]+\2\s*,\s*)
    (?P<quote>['\"])
    (?P<value>[^'\"]+)
    (?P=quote)
    """
)

_HTML_PASSWORD = re.compile(
    r"""(?ix)
    (?P<prefix><input\b[^>]*?\btype\s*=\s*(['\"])password\2[^>]*?\bvalue\s*=\s*)
    (?P<quote>['\"])
    (?P<value>[^'\"]*)
    (?P=quote)
    """
)

_HTML_PASSWORD_REV = re.compile(
    r"""(?ix)
    (?P<prefix><input\b[^>]*?\bvalue\s*=\s*)
    (?P<quote>['\"])
    (?P<value>[^'\"]*)
    (?P=quote)
    (?P<suffix>[^>]*?\btype\s*=\s*(['\"])password\5)
    """
)

_ROUTE = re.compile(
    r"""(?isx)(?:
        (?:route::|->)?post\s*\(\s*['\"]/?contact/?['\"]
      | ['\"]POST['\"]\s*[,=>:\s]{1,12}['\"]/?contact/?['\"]
      | ['\"]/?contact/?['\"]\s*[,=>:\s]{1,12}['\"]POST['\"]
      | ['\"]POST\s+/contact/?['\"]
      | REQUEST_METHOD['\"]?\s*\]\s*===?\s*['\"]POST['\"][\s\S]{0,500}?['\"]/?contact/?['\"]
      | ['\"]/?contact/?['\"][\s\S]{0,500}?REQUEST_METHOD['\"]?\s*\]\s*===?\s*['\"]POST['\"]
    )"""
)

_HANDLER = re.compile(
    r"""(?is)post\s*\(\s*['\"][^'\"]*contact[^'\"]*['\"]\s*,\s*(.{1,160})"""
)

_INCLUDE = re.compile(
    r"""(?i)(?:require|include)(?:_once)?\b(?:\s|\()(?:[^'\"\n]{0,120})?['\"]([^'\"]+\.php)['\"]"""
)

_CLASS = re.compile(r"\b([A-Z][A-Za-z0-9_]{2,})\b")

_EMAIL = re.compile(r"[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}")

_URL_HOST = re.compile(r"(?i)\bhttps?://([^/\s'\"<>]+)")
_DSN_HOST = re.compile(
    r"(?i)\b(?:postgres(?:ql)?|mysql|mariadb|mongodb(?:\+srv)?|redis|rediss|amqp|amqps)://"
    r"(?:[^@\s'\"<>]+@)?([A-Za-z0-9.-]+)"
)

_HOST_ASSIGN = re.compile(
    r"""(?i)(?:\bHost\b|\bhostname\b|\bsmtp[_-]?host\b|['\"]host['\"])\s*(?:=>|=|:)\s*['\"]([A-Za-z0-9.-]+)['\"]"""
)

_secrets: list[str] = []


class PullError(RuntimeError):
    """Hämtningen kan inte fortsätta. Inget skrevs till servern."""


class ReadOnlyFTP(SessionFTP):
    """Samma TLS-session som deploy-skriptet, med en lista över läskommandon."""

    def sendcmd(self, cmd: str) -> str:
        assert_read_only_command(cmd)
        return super().sendcmd(cmd)


@dataclass
class ContactReport:
    route_files: list[str] = field(default_factory=list)
    also: list[str] = field(default_factory=list)
    handler: str = ""
    calls: list[str] = field(default_factory=list)
    hosts: list[str] = field(default_factory=list)


@dataclass
class PullReport:
    files: list[str] = field(default_factory=list)
    skipped: dict[str, int] = field(default_factory=dict)
    contact: ContactReport = field(default_factory=ContactReport)


def assert_read_only_command(cmd: str) -> None:
    verb = cmd.split(None, 1)[0].upper() if cmd.strip() else ""
    if verb not in READ_VERBS:
        raise PullError(f"vägrat FTP-kommando: {verb}")


def register_secret(value: str) -> None:
    if value and len(value) >= 3 and value not in _secrets:
        _secrets.append(value)


def redact(text: str) -> str:
    for secret in _secrets:
        if secret:
            text = text.replace(secret, "***")
    return text


def has_secret_keyword(text: str) -> bool:
    spaced = re.sub(r"([a-z0-9])([A-Z])", r"\1 \2", text)
    normalized = re.sub(r"[_\-]+", " ", spaced)
    if _KEYWORD.search(normalized):
        return True
    lowered = normalized.casefold()
    return "supabase" in lowered and any(word in lowered for word in ("key", "anon", "service"))


def mask_text(text: str) -> str:
    """Byt ut värden som ser ut som nycklar, token, lösenord eller DSN."""
    for _name, pattern, replacement in _FORMAT_RULES:
        text = pattern.sub(replacement, text)
    text = _QUERY_SECRET.sub(lambda match: match.group(1) + MASK, text)

    def assign(match: re.Match[str]) -> str:
        if not has_secret_keyword(match.group("prefix")):
            return match.group(0)
        if match.group("value") == MASK:
            return match.group(0)
        quote = match.group("quote")
        return f"{match.group('prefix')}{quote}{MASK}{quote}"

    text = _ASSIGN.sub(assign, text)

    def define(match: re.Match[str]) -> str:
        if not has_secret_keyword(match.group("prefix")):
            return match.group(0)
        quote = match.group("quote")
        return f"{match.group('prefix')}{quote}{MASK}{quote}"

    text = _DEFINE.sub(define, text)

    def html_value(match: re.Match[str]) -> str:
        quote = match.group("quote")
        suffix = match.groupdict().get("suffix") or ""
        return f"{match.group('prefix')}{quote}{MASK}{quote}{suffix}"

    text = _HTML_PASSWORD.sub(html_value, text)
    text = _HTML_PASSWORD_REV.sub(html_value, text)
    return text


def find_unmasked(text: str) -> list[tuple[str, int]]:
    """Gitleaks-liknande regler. Träffar bara värden som maskeringen lämnade."""
    findings: list[tuple[str, int]] = []
    seen: set[tuple[str, int]] = set()

    def add(rule: str, start: int) -> None:
        line = text.count("\n", 0, start) + 1
        key = (rule, line)
        if key not in seen:
            seen.add(key)
            findings.append(key)

    for name, pattern, _replacement in _FORMAT_RULES:
        for match in pattern.finditer(text):
            add(name, match.start())
    for match in _QUERY_SECRET.finditer(text):
        add("generic-api-key", match.start())
    for match in _ASSIGN.finditer(text):
        if has_secret_keyword(match.group("prefix")) and match.group("value") != MASK:
            add("generic-api-key", match.start())
    for match in _DEFINE.finditer(text):
        if has_secret_keyword(match.group("prefix")) and match.group("value") != MASK:
            add("generic-api-key", match.start())
    return findings


def exclusion(root: str, rel: str, is_dir: bool) -> str | None:
    parts = tuple(part for part in rel.split("/") if part)
    if not parts:
        return None
    directory = parts if is_dir else parts[:-1]
    folded = [part.casefold() for part in directory]
    if any(part == "private" for part in folded):
        return "private"
    if root == API_ROOT and any(part == "vendor" for part in folded):
        return "vendor"
    pairs = folded if is_dir else [part.casefold() for part in parts]
    for left, right in zip(pairs, pairs[1:]):
        if left == "config" and right == "secret":
            return "config/secret"
    if is_dir:
        return None
    name = parts[-1].casefold()
    if name == ".env" or name.startswith(".env."):
        return ".env"
    if name.endswith(".key"):
        return "*.key"
    return None


def _bump(skipped: dict[str, int], reason: str) -> None:
    skipped[reason] = skipped.get(reason, 0) + 1


def text_of(name: str, data: bytes) -> str | None:
    if b"\x00" in data and not name.casefold().endswith((".php", ".js", ".html", ".htm", ".phtml")):
        return None
    try:
        return data.decode("utf-8")
    except UnicodeDecodeError:
        return data.decode("latin-1")


def should_scan(name: str, data: bytes) -> bool:
    if text_of(name, data) is None:
        return False
    lower = name.casefold()
    if lower.endswith((".php", ".phtml", ".inc", ".js", ".mjs", ".cjs", ".html", ".htm")):
        return True
    return b"\x00" not in data


def goto(ftp: ReadOnlyFTP, absolute: str) -> str:
    target = absolute.rstrip("/") or "/"
    candidates = [target]
    stripped = target.lstrip("/")
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
            pwd = ftp.pwd().rstrip("/") or "/"
            tail = target.lstrip("/")
            if pwd == target or pwd.endswith("/" + tail) or pwd.endswith(tail):
                return pwd
        except FTP_ERRORS as err:
            last = err
    raise PullError(f"kan inte öppna {target}") from last


def classify(ftp: ReadOnlyFTP, name: str) -> str:
    here = ftp.pwd()
    try:
        ftp.size(name)
        return "file"
    except FTP_ERRORS:
        pass
    try:
        ftp.cwd(name)
    except FTP_ERRORS:
        if ftp.pwd() != here:
            ftp.cwd(here)
        return "file"
    ftp.cwd(here)
    return "dir"


def safe_output(output: Path, root: str, rel: str) -> Path:
    parts = [part for part in root.strip("/").split("/") if part]
    if rel:
        parts.extend(part for part in rel.split("/") if part)
    base = output.resolve()
    current = base
    for part in parts:
        if not list_root_safe_name(part) or part in {".", ".."}:
            raise PullError("ogiltigt filnamn")
        current = current / part
    resolved = current.resolve()
    if os.path.commonpath([str(base), str(resolved)]) != str(base):
        raise PullError("sökväg utanför målet")
    return current


def mechanisms(text: str) -> list[str]:
    found: list[str] = []
    if re.search(r"\bmail\s*\(", text):
        found.append("mail")
    if re.search(r"PHPMailer", text, re.I):
        found.append("PHPMailer")
    if re.search(r"\bSMTP\b|isSMTP\s*\(|smtp[_-]?host|Swift_Smtp", text, re.I):
        found.append("SMTP")
    if re.search(r"supabase", text, re.I):
        found.append("Supabase")
    if re.search(r"\bcurl(?:_[A-Za-z_]+)?\s*\(", text):
        found.append("curl")
    return found


def _host_ok(host: str) -> bool:
    cleaned = host.strip(".").split("@")[-1].split(":")[0].strip("[]")
    if not cleaned or "@" in cleaned or cleaned == MASK:
        return False
    if cleaned.casefold() == "localhost":
        return True
    if re.fullmatch(r"\d{1,3}(?:\.\d{1,3}){3}", cleaned):
        return True
    return re.fullmatch(r"[A-Za-z0-9.-]+\.[A-Za-z]{2,}", cleaned) is not None


def extract_hosts(text: str) -> list[str]:
    hosts: set[str] = set()
    for match in _URL_HOST.finditer(text):
        host = match.group(1).split("@")[-1].split(":")[0].strip(".")
        if _host_ok(host):
            hosts.add(host.casefold())
    for match in _DSN_HOST.finditer(text):
        host = match.group(1).strip(".")
        if _host_ok(host):
            hosts.add(host.casefold())
    for match in _HOST_ASSIGN.finditer(text):
        host = match.group(1).strip(".")
        if _host_ok(host):
            hosts.add(host.casefold())
    return sorted(hosts)


def handler_label(text: str) -> str:
    match = _HANDLER.search(text)
    if not match:
        return ""
    raw = match.group(1).strip().split("\n", 1)[0].strip().rstrip(",);")
    raw = _EMAIL.sub("", raw)
    raw = mask_text(raw)
    if "@" in raw or len(raw) > 120:
        return ""
    return raw


def resolve_include(base_rel: str, target: str, sources: dict[str, str]) -> str | None:
    target = target.replace("\\", "/")
    if ".." in target.split("/"):
        return None
    if target.startswith("/"):
        suffix = target.lstrip("/")
        matches = [key for key in sources if key == suffix or key.endswith("/" + suffix)]
        return matches[0] if len(matches) == 1 else None
    rel = posixpath.normpath(posixpath.join(posixpath.dirname(base_rel), target))
    if rel in sources:
        return rel
    base = posixpath.basename(target)
    matches = [key for key in sources if posixpath.basename(key) == base]
    return matches[0] if len(matches) == 1 else None


def analyze_contact(sources: dict[str, str]) -> ContactReport:
    routes = [path for path, text in sorted(sources.items()) if _ROUTE.search(text)]
    report = ContactReport(route_files=routes)
    if not routes:
        return report
    seen: set[str] = set()
    depth = {path: 0 for path in routes}
    queue = list(routes)
    labels: list[str] = []
    while queue:
        path = queue.pop(0)
        if path in seen or path not in sources:
            continue
        seen.add(path)
        text = sources[path]
        label = handler_label(text)
        if label and label not in labels:
            labels.append(label)
        if depth.get(path, 0) >= 2:
            continue
        for include in _INCLUDE.findall(text):
            resolved = resolve_include(path, include, sources)
            if resolved and resolved not in seen:
                depth[resolved] = depth[path] + 1
                queue.append(resolved)
        if label:
            for class_name in _CLASS.findall(label):
                for candidate, body in sources.items():
                    if candidate in seen:
                        continue
                    if re.search(rf"\bclass\s+{re.escape(class_name)}\b", body):
                        depth[candidate] = depth[path] + 1
                        queue.append(candidate)
    ordered = [path for path in routes if path in seen]
    ordered.extend(path for path in seen if path not in routes)
    blob = "\n".join(sources[path] for path in ordered)
    report.also = [path for path in ordered if path not in routes]
    report.handler = "; ".join(labels)
    report.calls = mechanisms(blob)
    report.hosts = extract_hosts(blob)
    return report


def render_report(report: PullReport) -> str:
    lines = ["## Kalkylhämtning (skrivskyddad)", "", "Rötter:"]
    lines.extend(f"- {root}" for root in ROOTS)
    lines.append("")
    lines.append(f"Filer ({len(report.files)}):")
    lines.extend(f"- {path}" for path in report.files)
    if report.skipped:
        bits = [f"{name} {count}" for name, count in sorted(report.skipped.items())]
        lines.extend(["", "Överhoppade: " + ", ".join(bits)])
    lines.extend(["", "### POST /contact (api.kalkyl)"])
    contact = report.contact
    if not contact.route_files:
        lines.append("Ingen route-hanterare hittades.")
    else:
        lines.append("Hanterare: " + ", ".join(contact.route_files))
        if contact.handler:
            lines.append("Uttryck: " + contact.handler)
        if contact.also:
            lines.append("Läser även: " + ", ".join(contact.also))
        lines.append("Anrop: " + (", ".join(contact.calls) if contact.calls else "inga"))
        lines.append("Värdar: " + (", ".join(contact.hosts) if contact.hosts else "inga"))
    lines.append("")
    return "\n".join(lines)


def _note_skip(skipped: dict[str, int], reason: str) -> None:
    _bump(skipped, reason)


def download_roots(ftp: ReadOnlyFTP, output: Path) -> PullReport:
    """Hämta ROOTS till output. ``ftp`` får bara läsa."""
    report = PullReport()
    api_sources: dict[str, str] = {}
    seen_dirs: set[str] = set()
    output.mkdir(parents=True, exist_ok=True)

    def walk(root: str, rel: str, depth: int) -> None:
        if depth > 40:
            raise PullError("för djup katalog")
        absolute = posixpath.join(root, rel) if rel else root
        pwd = goto(ftp, absolute)
        if pwd in seen_dirs:
            return
        seen_dirs.add(pwd)
        entries = list_root_entries(ftp)
        if (ftp.pwd().rstrip("/") or "/") != pwd:
            goto(ftp, absolute)
        files: list[str] = []
        dirs: list[str] = []
        for name, kind in entries:
            if not list_root_safe_name(name):
                continue
            child = posixpath.join(rel, name) if rel else name
            if kind == "unknown":
                kind = classify(ftp, name)
                if ftp.pwd().rstrip("/") != pwd:
                    goto(ftp, absolute)
            if kind == "dir":
                reason = exclusion(root, child, True)
                if reason:
                    _note_skip(report.skipped, reason)
                    continue
                dirs.append(name)
            else:
                reason = exclusion(root, child, False)
                if reason:
                    _note_skip(report.skipped, reason)
                    continue
                files.append(name)
        for name in sorted(files):
            child = posixpath.join(rel, name) if rel else name
            if ftp.pwd().rstrip("/") != pwd:
                goto(ftp, absolute)
            data = retr_bytes(ftp, name, 65536)
            if len(data) > 8_000_000:
                raise PullError(f"för stor fil {root}/{child}")
            store(root, child, name, data, api_sources, report, output)
        for name in sorted(dirs):
            child = posixpath.join(rel, name) if rel else name
            walk(root, child, depth + 1)

    for root in ROOTS:
        walk(root, "", 0)
    report.files = sorted(report.files)
    report.contact = analyze_contact(api_sources)
    text = render_report(report)
    if "@" in text or find_unmasked(text):
        shutil.rmtree(output, ignore_errors=True)
        raise PullError("rapporten innehåller en adress eller ett omaskerat värde")
    (output / "REPORT.txt").write_text(text, encoding="utf-8")
    return report


def store(
    root: str,
    rel: str,
    name: str,
    data: bytes,
    api_sources: dict[str, str],
    report: PullReport,
    output: Path,
) -> None:
    display = posixpath.join(root.strip("/"), rel)
    original = text_of(name, data)
    if root == API_ROOT and original is not None and name.casefold().endswith((".php", ".phtml", ".inc")):
        api_sources[rel] = original
    if original is not None and should_scan(name, data):
        masked = mask_text(original)
        findings = find_unmasked(masked)
        if findings:
            shutil.rmtree(output, ignore_errors=True)
            shown = ", ".join(f"{rule}:{line}" for rule, line in findings[:20])
            raise PullError(f"omaskerade värden i {display} ({shown})")
        payload = masked.encode("utf-8")
    else:
        payload = data
    path = safe_output(output, root, rel)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(payload)
    report.files.append(display)


def write_summary(text: str) -> None:
    sys.stdout.write(text if text.endswith("\n") else text + "\n")
    summary = os.environ.get("GITHUB_STEP_SUMMARY", "").strip()
    if summary:
        with open(summary, "a", encoding="utf-8") as handle:
            handle.write(text if text.endswith("\n") else text + "\n")


def connect_readonly() -> ReadOnlyFTP:
    host = os.environ.get("LOOPIA_FTP_HOST", "").strip()
    user = os.environ.get("LOOPIA_FTP_USER", "").strip()
    password = os.environ.get("LOOPIA_FTP_PASS", "")
    register_secret(user)
    register_secret(password.strip())
    missing = [
        name
        for name, value in (
            ("LOOPIA_FTP_HOST", host),
            ("LOOPIA_FTP_USER", user),
            ("LOOPIA_FTP_PASS", password),
        )
        if not value
    ]
    if missing:
        raise PullError("saknar FTP-secrets: " + ", ".join(missing) + ". Ingen anslutning gjordes.")
    port = int(os.environ.get("LOOPIA_FTP_PORT", "21"))
    timeout = float(os.environ.get("JEAB_DEPLOY_TIMEOUT", "180"))
    context = ssl.create_default_context()
    ftp = ReadOnlyFTP(context=context)
    ftp.connect(host, port, timeout=timeout)
    try:
        ftp.login(user, password)
    except FTP_ERRORS as err:
        try:
            ftp.close()
        except Exception:
            pass
        raise PullError("FTP-inloggning nekades") from err
    ftp.prot_p()
    ftp.set_pasv(True)
    ftp.voidcmd("TYPE I")
    ftp.encoding = "utf-8"
    if ftp.sock is not None:
        ftp.sock.settimeout(timeout)
    return ftp


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Hämta kalkylträd. Bara läsning.")
    parser.add_argument("--output", required=True)
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(sys.argv[1:] if argv is None else argv)
    output = Path(args.output)
    if output.exists():
        shutil.rmtree(output)
    ftp: ReadOnlyFTP | None = None
    try:
        ftp = connect_readonly()
        print("transport ftp", flush=True)
        report = download_roots(ftp, output)
        write_summary(render_report(report))
        return 0
    finally:
        close_ftp(ftp)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except PullError as err:
        print(redact(str(err)), file=sys.stderr)
        sys.exit(1)
    except Exception as err:
        print(redact(f"avbruten: {type(err).__name__}: {err}"), file=sys.stderr)
        sys.exit(1)
