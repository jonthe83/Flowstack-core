#!/usr/bin/env python3
"""Stoppa nya PHP-skrivningar till .json/.jsonl under data/.

Allowlisten byggs från PHP på basen (main). En fil som redan skriver
JSON bidrar med de data-sökvägar den bygger. Tillagda rader får skriva
de vägarna, plus undantagen inställningar/mallar, hemligheter i
private/ och data/secrets, samt backuper och exporter.

RETIRED_JSON_PATHS tas bort från allowlisten. Anteckningar, skickade
SMS, inkorgens sidofiler och loggarna i src/log_store.php ligger i SQL.
En ny skrivning till dem stoppas även om basen fortfarande har den
gamla skrivningen.

Bara tillagda rader granskas. En oförändrad gammal skrivning är inte
en plus-rad och stoppar därför inte PR:en.

data/notes.json, data/sms-sent.json och inkorgens sidofiler
(corrections, hs-uncertain-open, quote-preview, auto-send-cap,
malmo-offer-sends, rebook-log) är flyttade till SQL och tas bort
från allowlisten även när basgrenen fortfarande skriver dem.
GI-lån skrivs till SQL på live MySQL. JSON läses som fallback tills migrering.
data/store.json och data/kontakter.json är flyttade till SQL och tas bort
från allowlisten även när basgrenen fortfarande skriver dem.
data/notes.json, data/sms-sent.json, inkorgens sidofiler (corrections,
hs-uncertain-open, quote-preview, auto-send-cap, malmo-offer-sends,
rebook-log) och Help Scout-arkivets json/jsonl är flyttade till SQL och
tas bort från allowlisten även när basgrenen fortfarande skriver dem.

--shrink eller NO_NEW_JSON_SHRINK=1 behandlar data/store.json,
data/kontakter.json, data/purchases.json och data/inkorg/* som
otillåtna. Default lämnar allowlisten och RETIRED_JSON_PATHS orörda.
Inga nya allowlist-rader.
"""

from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
from dataclasses import dataclass

JSON_EXT = r"jsonl?"

DATA_FILE_RE = re.compile(
    rf"""jeab_data_file\(\s*(['"])(?P<rel>[^'"]+\.{JSON_EXT})\1""",
    re.IGNORECASE,
)
DATA_DIR_RE = re.compile(
    rf"""(?P<fn>jeab_data_dir|jeab_public_data_dir|jeab_fellogg_data_dir|jeab_inkorg_dir)\(\)\s*\.\s*(['"])/?(?P<rel>[^'"]+\.{JSON_EXT})\2""",
    re.IGNORECASE,
)
INKORG_LOCKED_RE = re.compile(
    rf"""jeab_inkorg_locked\(\s*(['"])(?P<name>[^'"]+\.{JSON_EXT})\1""",
    re.IGNORECASE,
)
DATA_LITERAL_RE = re.compile(
    rf"""['"]([^'"]*data/[^'"]+\.{JSON_EXT})['"]""",
    re.IGNORECASE,
)
# jeab_data_dir() i en ternary, eller jeab_hs_import_root() . '/index.jsonl'.
FLEX_DIR_RE = re.compile(
    rf"""(?P<fn>jeab_data_dir|jeab_public_data_dir|jeab_fellogg_data_dir|jeab_inkorg_dir|jeab_hs_import_root)\(\)(?:(?!;).){{0,180}}?['"]/?(?P<rel>[^'"]+\.{JSON_EXT})['"]""",
    re.IGNORECASE | re.DOTALL,
)
SECRET_NAME_RE = re.compile(
    rf"""(?:jeab_graph_secret_path|jeab_webshop_secret_path|jeab_webshop_secret_write)\(\s*['"](?P<name>[^'"]+\.{JSON_EXT})['"]""",
    re.IGNORECASE,
)
PLAIN_WRITE_RE = re.compile(
    r"""\b(file_put_contents|fwrite|fputs|jeab_inkorg_locked|jeab_graph_json_write|jeab_fellogg_json_write|jeab_purchase_json_write|jeab_bug_write_json)\s*\("""
)
JSON_STORE_RE = re.compile(r"""\bjeab_open_json_store\s*\(|(?<![.\w])json_store\s*\(""")
NEW_STORE_RE = re.compile(r"""\bnew\s+JeabJsonStore\b""")

SKIP_PREFIXES = ("tests/", "vendor/", "node_modules/")

# Flyttade till SQL. Basens gamla skrivning ska inte göra vägen tillåten.
RETIRED_JSON_PATHS = frozenset({
    "data/notes.json",
    "data/sms-sent.json",
    "data/inkorg/corrections.jsonl",
    "data/inkorg/hs-uncertain-open.json",
    "data/inkorg/quote-preview.json",
    "data/inkorg/auto-send-cap.json",
    "data/inkorg/malmo-offer-sends.json",
    "data/inkorg/rebook-log.json",
    "data/inkorg/graph-hook-queue.json",
    "data/purchases.json",
    "data/ordrar-deleted.json",
    "data/code-people.json",
    "data/reminder-ort-cache.json",
    "data/reminders-prune.json",
    "data/webshop-catchup.json",
    "data/inkorg/helpscout-import.json",
    "data/inkorg/malmo-hs-sync.json",
    "data/inkorg/hs-import/index.jsonl",
    "data/inkorg/hs-import/.json",
    "data/calendar-log.json",
    "data/graph-person-events.json",
    "data/graph-week-cache.json",
    "data/who-diag.jsonl",
    "data/who-picks.json",
    "data/inkorg/reminder-follow.jsonl",
    "data/inkorg/reminder-hook.jsonl",
    "data/webshop-orders.json",
    "data/app-errors.jsonl",
    "data/fellogg-open-asks.json",
    "data/store.json",
    "data/kontakter.json",
})

# Valfritt läge. Lägger inte till rader och ändrar inte RETIRED_JSON_PATHS.
SHRINK_EXACT = frozenset({
    "data/store.json",
    "data/kontakter.json",
    "data/purchases.json",
})


@dataclass
class Violation:
    path: str
    line: int
    reason: str
    snippet: str


@dataclass
class AddedStmt:
    path: str
    line: int
    text: str
    hunk: int


def strip_comments(src: str) -> str:
    out: list[str] = []
    i = 0
    n = len(src)
    state = "code"
    while i < n:
        c = src[i]
        nxt = src[i + 1] if i + 1 < n else ""
        if state == "code":
            if c in ("'", '"'):
                state = c
                out.append(c)
            elif c == "/" and nxt == "/":
                state = "line"
                i += 1
            elif c == "/" and nxt == "*":
                state = "block"
                i += 1
            elif c == "#":
                state = "line"
            else:
                out.append(c)
        elif state in ("'", '"'):
            out.append(c)
            if c == "\\" and i + 1 < n:
                i += 1
                out.append(src[i])
            elif c == state:
                state = "code"
        elif state == "line":
            if c == "\n":
                state = "code"
                out.append("\n")
        elif state == "block":
            if c == "*" and nxt == "/":
                state = "code"
                i += 1
            elif c == "\n":
                out.append("\n")
        i += 1
    return "".join(out)


def logical_data_path(path: str) -> str:
    p = path.replace("\\", "/").strip()
    lower = p.lower()
    idx = lower.find("data/")
    if idx >= 0:
        p = "data/" + p[idx + len("data/") :]
    else:
        p = "data/" + p.lstrip("/")
    p = re.sub(r"/+", "/", p)
    if p.startswith("data/.private/"):
        p = "data/" + p[len("data/.private/") :]
    return p


def extract_paths(text: str) -> list[str]:
    found: list[str] = []
    for match in DATA_FILE_RE.finditer(text):
        found.append(logical_data_path(match.group("rel")))
    for match in DATA_DIR_RE.finditer(text):
        rel = match.group("rel").lstrip("/")
        if match.group("fn") == "jeab_inkorg_dir":
            rel = "inkorg/" + rel
        found.append(logical_data_path(rel))
    for match in INKORG_LOCKED_RE.finditer(text):
        found.append(logical_data_path("inkorg/" + match.group("name").lstrip("/")))
    for match in DATA_LITERAL_RE.finditer(text):
        found.append(logical_data_path(match.group(1)))
    for match in FLEX_DIR_RE.finditer(text):
        rel = match.group("rel").lstrip("/")
        fn = match.group("fn")
        if fn == "jeab_inkorg_dir":
            rel = "inkorg/" + rel
        elif fn == "jeab_hs_import_root":
            rel = "inkorg/hs-import/" + rel
        found.append(logical_data_path(rel))
    for match in SECRET_NAME_RE.finditer(text):
        found.append(logical_data_path("secrets/" + match.group("name").lstrip("/")))
    seen: set[str] = set()
    out: list[str] = []
    for path in found:
        if path not in seen and path.lower().endswith((".json", ".jsonl")):
            seen.add(path)
            out.append(path)
    return out


def is_exception_path(path: str) -> bool:
    base = path.rsplit("/", 1)[-1].lower()
    low = path.lower()
    if base == "settings.json":
        return True
    if "mall" in base or "template" in base:
        return True
    if "/secrets/" in f"/{low}":
        return True
    if low.startswith(("data/private/", "private/", "privat/")):
        return True
    if any(tok in low for tok in ("backup", "/export", "export.", ".bak", ".prev", "bak-")):
        return True
    return False


def statement_is_exception(text: str) -> bool:
    low = text.lower()
    if "settings.json" in low:
        return True
    if re.search(r"mallar?\.json|templates?\.json", low):
        return True
    if "/secrets/" in low or re.search(r"""['"]secrets/""", low):
        return True
    if re.search(r"(?<![\w.])(private|privat)/", low):
        return True
    if re.search(r"(backup|export)\.json|\.bak|\.prev|['\"][^'\"]*backup[^'\"]*\.json", low):
        return True
    return False


def fopen_is_write(text: str) -> bool:
    for match in re.finditer(r"\bfopen\s*\(", text):
        mode = re.search(r""",\s*(['"])([A-Za-z+]{1,5})\1""", text[match.end() :])
        if mode and any(ch in mode.group(2) for ch in "waxc+"):
            return True
    return False


def is_write(text: str) -> bool:
    if JSON_STORE_RE.search(text) or NEW_STORE_RE.search(text) or PLAIN_WRITE_RE.search(text):
        return True
    return bool(re.search(r"\bfopen\s*\(", text) and fopen_is_write(text))


def mentions_data_json(text: str) -> bool:
    if not re.search(rf"\.{JSON_EXT}\b", text, re.IGNORECASE):
        return False
    if re.search(
        r"\b(jeab_data_dir|jeab_data_file|jeab_public_data_dir|jeab_fellogg_data_dir|jeab_inkorg_dir|jeab_inkorg_locked|jeab_hs_import_root)\s*\(",
        text,
    ):
        return True
    return "data/" in text.lower()


def skip_php(path: str) -> bool:
    norm = path.replace("\\", "/").lstrip("./")
    if not norm.endswith(".php"):
        return True
    return norm.startswith(SKIP_PREFIXES)


def scan_php_text(text: str) -> set[str]:
    stripped = strip_comments(text)
    if "JeabJsonStore" not in stripped and not is_write(stripped):
        return set()
    return set(extract_paths(stripped))


def scan_tree(root: str) -> set[str]:
    import os

    allow: set[str] = set()
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d not in (".git", "vendor", "node_modules", "tests")]
        for name in filenames:
            if not name.endswith(".php"):
                continue
            rel = os.path.relpath(os.path.join(dirpath, name), root).replace("\\", "/")
            if skip_php(rel):
                continue
            try:
                with open(os.path.join(dirpath, name), encoding="utf-8", errors="replace") as fh:
                    src = fh.read()
            except OSError:
                continue
            allow.update(scan_php_text(src))
    return allow


def scan_git_ref(ref: str) -> set[str]:
    listed = subprocess.check_output(["git", "ls-tree", "-r", "--name-only", ref], text=True)
    paths = [p for p in listed.splitlines() if p.endswith(".php") and not skip_php(p)]
    if not paths:
        return set()
    payload = "".join(f"{ref}:{path}\n" for path in paths).encode()
    proc = subprocess.run(["git", "cat-file", "--batch"], input=payload, capture_output=True, check=True)
    return parse_cat_file(proc.stdout)


def parse_cat_file(data: bytes) -> set[str]:
    allow: set[str] = set()
    index = 0
    total = len(data)
    while index < total:
        nl = data.find(b"\n", index)
        if nl < 0:
            break
        header = data[index:nl].decode("utf-8", "replace")
        index = nl + 1
        parts = header.split()
        if len(parts) < 3 or parts[-1] == "missing":
            continue
        size = int(parts[-1])
        blob = data[index : index + size]
        index += size
        if index < total and data[index : index + 1] == b"\n":
            index += 1
        allow.update(scan_php_text(blob.decode("utf-8", "replace")))
    return allow


def parse_added_statements(diff: str) -> list[AddedStmt]:
    stmts: list[AddedStmt] = []
    path = ""
    skip = False
    new_line = 0
    hunk = 0
    buf: list[str] = []
    buf_line = 0
    in_string: str | None = None

    def flush() -> None:
        nonlocal buf, buf_line, in_string
        if buf and path and not skip:
            text = strip_comments("\n".join(buf))
            if text.strip():
                stmts.append(AddedStmt(path, buf_line, text, hunk))
        buf = []
        buf_line = 0
        in_string = None

    for raw in diff.splitlines():
        if raw.startswith("diff --git "):
            flush()
            path = ""
            skip = False
            hunk += 1
            continue
        if raw.startswith("+++ "):
            name = raw[4:].strip()
            if name == "/dev/null":
                path = ""
                skip = True
            else:
                path = name[2:] if name.startswith("b/") else name
                skip = skip_php(path)
            continue
        if raw.startswith("@@"):
            flush()
            hunk += 1
            match = re.search(r"\+(\d+)", raw)
            new_line = int(match.group(1)) if match else 0
            continue
        if raw.startswith("\\") or path == "" or skip:
            continue
        if raw.startswith("+"):
            text = raw[1:]
            if not buf:
                buf_line = new_line
            buf.append(text)
            in_string = ends_in_string(text, in_string)
            if in_string is None and code_has_semicolon(text):
                flush()
            new_line += 1
            continue
        if raw.startswith("-"):
            continue
        flush()
        new_line += 1
    flush()
    return stmts


def code_has_semicolon(text: str) -> bool:
    state: str | None = None
    i = 0
    while i < len(text):
        c = text[i]
        if state is None:
            if c in ("'", '"'):
                state = c
            elif c == ";":
                return True
        elif c == "\\":
            i += 1
        elif c == state:
            state = None
        i += 1
    return False


def ends_in_string(text: str, state: str | None) -> str | None:
    i = 0
    while i < len(text):
        c = text[i]
        if state is None:
            if c in ("'", '"'):
                state = c
        elif c == "\\":
            i += 1
        elif c == state:
            state = None
        i += 1
    return state


def check_statements(stmts: list[AddedStmt], allow: set[str], shrink: bool = False) -> list[Violation]:
    violations: list[Violation] = []
    for stmt in stmts:
        text = stmt.text
        if not is_write(text):
            continue
        if JSON_STORE_RE.search(text):
            violations.append(Violation(stmt.path, stmt.line, "nytt json_store-anrop", snippet(text)))
            continue
        if NEW_STORE_RE.search(text):
            paths = extract_paths(text)
            if paths and all(path_ok(p, allow, shrink) for p in paths):
                continue
            if not paths and statement_is_exception(text):
                continue
            bad = [p for p in paths if not path_ok(p, allow, shrink)] or ["(okänd sökväg)"]
            violations.append(Violation(stmt.path, stmt.line, "ny JeabJsonStore mot " + ", ".join(bad), snippet(text)))
            continue
        if re.search(r"\bfopen\s*\(", text) and not fopen_is_write(text) and not PLAIN_WRITE_RE.search(text):
            continue
        if not mentions_data_json(text):
            continue
        paths = extract_paths(text)
        if not paths:
            if statement_is_exception(text):
                continue
            violations.append(
                Violation(stmt.path, stmt.line, "ny skrivning till json under data/ utan känd sökväg", snippet(text))
            )
            continue
        bad = [p for p in paths if not path_ok(p, allow, shrink)]
        if bad:
            violations.append(Violation(stmt.path, stmt.line, "ny skrivning till " + ", ".join(bad), snippet(text)))
    violations.extend(check_split_writes(stmts, allow, violations, shrink))
    return violations


def check_split_writes(stmts: list[AddedStmt], allow: set[str], already: list[Violation], shrink: bool = False) -> list[Violation]:
    """Fångar file_put_contents($path) när sökvägen sattes på en annan tillagd rad i samma hunk."""
    flagged = {(item.path, item.line) for item in already}
    groups: dict[tuple[str, int], list[AddedStmt]] = {}
    for stmt in stmts:
        groups.setdefault((stmt.path, stmt.hunk), []).append(stmt)
    extra: list[Violation] = []
    for group in groups.values():
        direct: set[str] = set()
        all_paths: list[str] = []
        for stmt in group:
            found = extract_paths(stmt.text)
            all_paths.extend(found)
            if is_write(stmt.text):
                direct.update(found)
        loose = []
        seen: set[str] = set()
        for path in all_paths:
            if path in seen or path in direct or path_ok(path, allow, shrink):
                continue
            seen.add(path)
            loose.append(path)
        if not loose:
            continue
        for stmt in group:
            if (stmt.path, stmt.line) in flagged:
                continue
            if extract_paths(stmt.text):
                continue
            if not is_write(stmt.text) or JSON_STORE_RE.search(stmt.text) or NEW_STORE_RE.search(stmt.text):
                continue
            extra.append(
                Violation(
                    stmt.path,
                    stmt.line,
                    "ny skrivning till " + ", ".join(loose),
                    snippet(stmt.text),
                )
            )
    return extra


def without_retired(allow: set[str]) -> set[str]:
    return {path for path in allow if path not in RETIRED_JSON_PATHS}


def is_shrink_path(path: str) -> bool:
    return path in SHRINK_EXACT or path.startswith("data/inkorg/")


def shrink_enabled(cli: bool = False) -> bool:
    if cli:
        return True
    return os.environ.get("NO_NEW_JSON_SHRINK") == "1"


def path_ok(path: str, allow: set[str], shrink: bool = False) -> bool:
    if shrink and is_shrink_path(path):
        return False
    if path in RETIRED_JSON_PATHS:
        return False
    return is_exception_path(path) or path in allow


def snippet(text: str) -> str:
    return " ".join(text.split())[:240]


def git_diff(base: str) -> str:
    return subprocess.check_output(
        ["git", "diff", "--no-ext-diff", "--unified=3", f"{base}...HEAD"],
        text=True,
    )


def print_allowlist(allow: set[str]) -> None:
    print(f"allowlist {len(allow)}")
    for path in sorted(allow):
        print(path)


def run_check(diff: str, allow: set[str], shrink: bool = False) -> int:
    violations = check_statements(parse_added_statements(diff), allow, shrink)
    if not violations:
        print(f"inga nya JSON-skrivningar under data/ (allowlist {len(allow)})")
        return 0
    print(f"nya JSON-skrivningar under data/ ({len(violations)}):", file=sys.stderr)
    for item in violations:
        print(f"{item.path}:{item.line}: {item.reason}", file=sys.stderr)
        print(f"  {item.snippet}", file=sys.stderr)
    return 1


def diff_with(added: str, context: str = "<?php") -> str:
    plus = "\n".join("+" + line for line in added.splitlines())
    ctx = "\n".join(" " + line for line in context.splitlines())
    return (
        "diff --git a/src/fixture.php b/src/fixture.php\n"
        "--- a/src/fixture.php\n"
        "+++ b/src/fixture.php\n"
        "@@ -1,1 +1,8 @@\n"
        f"{ctx}\n"
        f"{plus}\n"
    )


def self_test() -> int:
    allow = {
        "data/notes.json",
        "data/inkorg/contacts.json",
        "data/store.json",
        "data/inkorg/auto-send-cap.json",
        "data/inkorg/malmo-offer-sends.json",
        "data/inkorg/helpscout-import.json",
        "data/inkorg/malmo-hs-sync.json",
        "data/inkorg/hs-import/index.jsonl",
        "data/inkorg/hs-import/.json",
        "data/inkorg/graph-hook-queue.json",
    }
    cases = [
        ("block-new-file", diff_with("file_put_contents(jeab_data_dir() . '/leads.json', json_encode($rows));"), 1),
        ("block-fopen", diff_with("$fh = fopen(jeab_data_dir() . '/leads.jsonl', 'a');"), 1),
        ("block-json-store", diff_with("return jeab_open_json_store();"), 1),
        ("block-new-jeab-json-store", diff_with("return new JeabJsonStore(jeab_data_file('leads.json'));"), 1),
        ("pass-settings", diff_with("file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode($s));"), 0),
        ("pass-allowlisted", diff_with("file_put_contents(jeab_inkorg_dir() . '/contacts.json', $json, LOCK_EX);"), 0),
        ("block-retired-notes", diff_with("file_put_contents(jeab_data_dir() . '/notes.json', $json, LOCK_EX);"), 1),
        ("block-retired-sms", diff_with("file_put_contents(jeab_data_file('sms-sent.json'), $json);"), 1),
        ("block-retired-auto-cap", diff_with("file_put_contents(jeab_inkorg_dir() . '/auto-send-cap.json', $json, LOCK_EX);"), 1),
        (
            "block-retired-malmo-sends",
            diff_with("$path = jeab_inkorg_dir() . '/malmo-offer-sends.json';\nfile_put_contents($path, $json);"),
            1,
        ),
        ("block-retired-hs-index", diff_with("$fh = fopen(jeab_hs_import_root() . '/index.jsonl', 'ab');"), 1),
        ("block-retired-hs-checkpoint", diff_with("file_put_contents(jeab_inkorg_dir() . '/helpscout-import.json', $json);"), 1),
        ("block-retired-malmo-sync", diff_with("file_put_contents(jeab_inkorg_dir() . '/malmo-hs-sync.json', $json);"), 1),
        ("block-retired-hs-conv", diff_with("file_put_contents(jeab_hs_import_root() . '/c/aa/' . $id . '.json', $json);"), 1),
        ("block-retired-graph-hook", diff_with("jeab_inkorg_locked('graph-hook-queue.json', function (?array $d) { return [0, $d]; });"), 1),
        ("pass-secret", diff_with("file_put_contents(jeab_data_dir() . '/secrets/new-api.json', json_encode($k));"), 0),
        ("pass-backup", diff_with("file_put_contents(jeab_data_dir() . '/kontakter.backup.json', $json);"), 0),
        ("pass-temp", diff_with("file_put_contents($tmp . '/cases.json', json_encode($payload));"), 0),
        (
            "pass-context-only",
            "diff --git a/src/fixture.php b/src/fixture.php\n"
            "--- a/src/fixture.php\n"
            "+++ b/src/fixture.php\n"
            "@@ -1,2 +1,3 @@\n"
            " file_put_contents(jeab_data_dir() . '/brand-new.json', $x);\n"
            "+$ok = true;\n",
            0,
        ),
        (
            "block-multiline",
            diff_with("file_put_contents(\n    jeab_data_dir() . '/leads.json',\n    json_encode($rows)\n);"),
            1,
        ),
        (
            "pass-test-file",
            "diff --git a/tests/fixture_test.php b/tests/fixture_test.php\n"
            "--- a/tests/fixture_test.php\n"
            "+++ b/tests/fixture_test.php\n"
            "@@ -1 +1,2 @@\n"
            "+file_put_contents(jeab_data_dir() . '/leads.json', '{}');\n",
            0,
        ),
        (
            "block-retired-store-ctor",
            diff_with("return new JeabJsonStore(jeab_data_file('store.json'));"),
            1,
        ),
        (
            "block-split-path",
            diff_with("$path = jeab_data_dir() . '/leads.json';\nfile_put_contents($path, $json);"),
            1,
        ),
        (
            "pass-split-allowlisted",
            diff_with("$path = jeab_data_dir() . '/inkorg/contacts.json';\nfile_put_contents($path, $json);"),
            0,
        ),
        (
            "block-split-retired",
            diff_with("$path = jeab_data_dir() . '/sms-sent.json';\nfile_put_contents($path, $json);"),
            1,
        ),
    ]
    failed = 0
    for name, diff, expect in cases:
        got = 1 if check_statements(parse_added_statements(diff), allow) else 0
        if got != expect:
            failed += 1
            print(f"FAIL {name}: väntade {expect}, fick {got}", file=sys.stderr)
            for item in check_statements(parse_added_statements(diff), allow):
                print(f"  {item.reason}: {item.snippet}", file=sys.stderr)
        else:
            print(f"ok {name}")
    retired_allow = without_retired({
        "data/calendar-log.json",
        "data/notes.json",
        "data/sms-sent.json",
        "data/app-errors.jsonl",
        "data/inkorg/contacts.json",
        "data/inkorg/auto-send-cap.json",
    })
    retired_cases = [
        ("block-retired-calendar", diff_with("file_put_contents(jeab_data_dir() . '/calendar-log.json', $json);"), 1),
        ("block-retired-errors", diff_with("file_put_contents(jeab_fellogg_data_dir() . '/app-errors.jsonl', $line, FILE_APPEND);"), 1),
        ("pass-contacts-after-retire", diff_with("file_put_contents(jeab_inkorg_dir() . '/contacts.json', $json, LOCK_EX);"), 0),
    ]
    for name, diff, expect in retired_cases:
        got = 1 if check_statements(parse_added_statements(diff), retired_allow) else 0
        if got != expect:
            failed += 1
            print(f"FAIL {name}: väntade {expect}, fick {got}", file=sys.stderr)
        else:
            print(f"ok {name}")
    if retired_allow != {"data/inkorg/contacts.json"}:
        failed += 1
        print("FAIL retire-filter", file=sys.stderr)
    else:
        print("ok retire-filter")
    if "data/store.json" in RETIRED_JSON_PATHS and "data/kontakter.json" in RETIRED_JSON_PATHS:
        print("ok store-kontakter-retired")
    else:
        failed += 1
        print("FAIL store-kontakter-retired", file=sys.stderr)
    shrink_allow = {
        "data/store.json",
        "data/kontakter.json",
        "data/purchases.json",
        "data/inkorg/contacts.json",
        "data/offert-defaults.json",
    }
    shrink_cases = [
        ("retired-blocks-store-without-shrink", diff_with("file_put_contents(jeab_data_file('store.json'), $json);"), False, 1),
        ("shrink-blocks-store", diff_with("file_put_contents(jeab_data_file('store.json'), $json);"), True, 1),
        ("shrink-blocks-kontakter", diff_with("file_put_contents(jeab_data_dir() . '/kontakter.json', $json);"), True, 1),
        ("shrink-blocks-purchases", diff_with("file_put_contents(jeab_data_file('purchases.json'), $json);"), True, 1),
        ("shrink-blocks-inkorg", diff_with("file_put_contents(jeab_inkorg_dir() . '/contacts.json', $json, LOCK_EX);"), True, 1),
        ("shrink-blocks-inkorg-settings", diff_with("file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode($s));"), True, 1),
        ("shrink-off-inkorg", diff_with("file_put_contents(jeab_inkorg_dir() . '/contacts.json', $json, LOCK_EX);"), False, 0),
        ("shrink-off-settings", diff_with("file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode($s));"), False, 0),
        ("shrink-allows-other", diff_with("file_put_contents(jeab_data_dir() . '/offert-defaults.json', $json);"), True, 0),
    ]
    for name, diff, shrink, expect in shrink_cases:
        got = 1 if check_statements(parse_added_statements(diff), shrink_allow, shrink) else 0
        if got != expect:
            failed += 1
            print(f"FAIL {name}: väntade {expect}, fick {got}", file=sys.stderr)
            for item in check_statements(parse_added_statements(diff), shrink_allow, shrink):
                print(f"  {item.reason}: {item.snippet}", file=sys.stderr)
        else:
            print(f"ok {name}")
    old_shrink = os.environ.get("NO_NEW_JSON_SHRINK")
    os.environ["NO_NEW_JSON_SHRINK"] = "1"
    env_on = shrink_enabled(False)
    os.environ["NO_NEW_JSON_SHRINK"] = "0"
    env_off = shrink_enabled(False)
    cli_on = shrink_enabled(True)
    if old_shrink is None:
        os.environ.pop("NO_NEW_JSON_SHRINK", None)
    else:
        os.environ["NO_NEW_JSON_SHRINK"] = old_shrink
    if env_on and not env_off and cli_on:
        print("ok shrink-env")
    else:
        failed += 1
        print("FAIL shrink-env", file=sys.stderr)
    if failed:
        print(f"självtest: {failed} fel", file=sys.stderr)
        return 1
    print("självtest ok")
    return 0


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description="Stoppa nya JSON-skrivningar under data/.")
    parser.add_argument("--base", help="Git-ref för allowlist och diff, t.ex. origin/main")
    parser.add_argument("--scan-root", help="Katalog att skanna i stället för en git-ref")
    parser.add_argument("--diff", help="Unified diff att granska")
    parser.add_argument("--print-allowlist", action="store_true")
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--shrink", action="store_true", help="Behandla store.json, kontakter.json, purchases.json och data/inkorg/* som otillåtna")
    args = parser.parse_args(argv)
    if args.self_test:
        return self_test()
    shrink = shrink_enabled(args.shrink)
    if args.scan_root:
        allow = scan_tree(args.scan_root)
    elif args.base:
        allow = scan_git_ref(args.base)
    else:
        print("ange --base eller --scan-root", file=sys.stderr)
        return 2
    allow = without_retired(allow)
    if args.print_allowlist:
        print_allowlist(allow)
    if args.diff:
        diff = open(args.diff, encoding="utf-8").read()
        return run_check(diff, allow, shrink)
    if args.base and not args.print_allowlist:
        return run_check(git_diff(args.base), allow, shrink)
    if args.base and args.print_allowlist and args.diff is None and args.scan_root is None:
        return 0
    if not args.print_allowlist:
        print("ange --diff eller --base", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main(sys.argv[1:]))
    except subprocess.CalledProcessError as exc:
        err = exc.stderr
        if isinstance(err, bytes):
            err = err.decode("utf-8", "replace")
        print(err or exc, file=sys.stderr)
        sys.exit(exc.returncode or 1)
