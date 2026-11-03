#!/usr/bin/env python3
"""Kör tests/*.php i en processlista.

Utan argument körs alla tester (lokalt). Med --changed körs bara tester
vars filnamn börjar med stammen på en ändrad src/*.php, plus ändrade
tester, högst --cap stycken och inom --budget sekunder.

Kända fel skrivs ut och fäller inte jobbet. Ett annat fel i samma fil fäller.
"""

from __future__ import annotations

import atexit
import argparse
import os
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TESTS = ROOT / "tests"
ALLOW = ROOT / ".github" / "php-test-known-failures.txt"
TIMEOUT = int(os.environ.get("PHP_TEST_TIMEOUT", "20"))


def allowlist() -> dict[str, list[str]]:
    """Sökväg → known-rader. Samma sökväg flera gånger ger flera exakta «FAIL »-rader."""
    known: dict[str, list[str]] = {}
    if not ALLOW.is_file():
        return known
    for raw in ALLOW.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        path, _, needle = line.partition("\t")
        path = path.strip()
        needle = needle.strip()
        if not path or not needle:
            raise SystemExit(f"allowlist-rad saknar tab och text: {raw}")
        have = known.setdefault(path, [])
        if have and not (needle.startswith("FAIL ") and all(n.startswith("FAIL ") for n in have)):
            raise SystemExit(f"flera rader för samma test måste alla börja med «FAIL »: {raw}")
        have.append(needle)
    return known


# Inloggning och person från kodprefix: ändringar i de här filerna kör hela kedjan,
# inte bara tester vars namn råkar börja med filens stam.
LOGIN_IDENTITY_TESTS = [
    "tests/boot_login_smoke_test.php",
    "tests/login_diag_assign_test.php",
    "tests/who_default_login_test.php",
    "tests/sam_city_identity_test.php",
    "tests/code_prefix_identity_test.php",
    "tests/admin_person_ids_test.php",
    "tests/admin_personal_login_test.php",
    "tests/auth_session_user_repair_test.php",
    "tests/login_auto_user_test.php",
    "tests/login_auto_user_config_test.php",
    "tests/code_people_live_source_test.php",
    "tests/samw_office_test.php",
]
RELATED = {
    "src/people.php": LOGIN_IDENTITY_TESTS,
    "src/auth.php": LOGIN_IDENTITY_TESTS,
    "src/who_identity.php": LOGIN_IDENTITY_TESTS,
}


def known_matches(needles: list[str], output: str) -> bool:
    """«FAIL »-rader: mängden FAIL-rader i output måste vara exakt mängden known-rader.
    En ny eller saknad FAIL-rad är ett oväntat fel. Äldre rader utan «FAIL » matchar
    som förut på text var som helst i output."""
    # En krasch är aldrig ett känt fel, även om FAIL-raderna stämmer.
    if "PHP Fatal" in output or "Uncaught" in output:
        return False
    if len(needles) == 1 and not needles[0].startswith("FAIL "):
        return needles[0] in output
    fails = [line.strip() for line in output.splitlines() if line.strip().startswith("FAIL")]
    return len(fails) == len(set(fails)) and set(fails) == set(needles)


def select_tests(changed: list[str], cap: int) -> tuple[list[Path], list[str]]:
    """Tester som hör till ändrade src-filer, plus tester som själva ändrats.

    Matchningen är filnamn: src/sms.php väljer tests/sms*.php.
    Kortaste namnen går först. Resten över --cap körs inte.
    """
    stems: list[str] = []
    direct: list[Path] = []
    for raw in changed:
        path = raw.strip().replace("\\", "/")
        if path.startswith("tests/") and path.endswith(".php"):
            candidate = ROOT / path
            if candidate.is_file():
                direct.append(candidate)
        if path.startswith("src/") and path.endswith(".php"):
            stems.append(Path(path).stem)
        for rel in RELATED.get(path, []):
            candidate = ROOT / rel
            if candidate.is_file() and candidate not in direct:
                direct.append(candidate)
    pooled: list[Path] = []
    seen: set[str] = set()
    for path in direct:
        rel = path.relative_to(ROOT).as_posix()
        if rel not in seen:
            seen.add(rel)
            pooled.append(path)
    for stem in stems:
        if not stem:
            continue
        for path in sorted(TESTS.glob(f"{stem}*.php")):
            rel = path.relative_to(ROOT).as_posix()
            if rel not in seen:
                seen.add(rel)
                pooled.append(path)

    direct_rels = {path.relative_to(ROOT).as_posix() for path in direct}

    def rank(path: Path) -> tuple[int, int, int, str]:
        name = path.name
        rel = path.relative_to(ROOT).as_posix()
        edited = 0 if rel in direct_rels else 1
        exact = 0 if any(name == f"{stem}_test.php" for stem in stems) else 1
        return (edited, exact, len(name), name)

    pooled.sort(key=rank)
    chosen = pooled[:cap]
    skipped = [path.relative_to(ROOT).as_posix() for path in pooled[cap:]]
    return chosen, skipped


def enable_store_prepend() -> None:
    """Extra ini så barnprocesser får jeab_store_pdo_override utan att src laddar tests/.

    Ledande avskiljare i PHP_INI_SCAN_DIR lägger katalogen efter PHP:s vanliga
    scan-dir. CLI och php -S ärver variabeln när de inte byter ut miljön.
    """
    ini_dir = Path(tempfile.mkdtemp(prefix="jeab-php-ini-"))
    prepend = (TESTS / "jeab_test_sqlite.php").resolve()
    (ini_dir / "zz-jeab-test.ini").write_text(
        f'auto_prepend_file="{prepend}"\n',
        encoding="utf-8",
    )
    os.environ["PHP_INI_SCAN_DIR"] = os.pathsep + str(ini_dir)
    atexit.register(lambda: shutil.rmtree(ini_dir, ignore_errors=True))


def main() -> int:
    enable_store_prepend()
    # Tester som stänger av sqlite ska öppna en temp-sqlite i stället för JSON.
    # JEAB_ASSERT_SQL_DOWN i ett enskilt test behåller det hårda felet.
    os.environ.setdefault("JEAB_TEST_TEMP_SQLITE", "1")
    parser = argparse.ArgumentParser()
    parser.add_argument("--changed", default="", help="Fil med git diff --name-only. Tomt = alla tester.")
    parser.add_argument("--cap", type=int, default=24)
    parser.add_argument("--budget", type=int, default=150, help="Sekunder för själva testerna.")
    args = parser.parse_args()
    known = allowlist()
    skipped: list[str] = []
    if args.changed:
        changed_path = Path(args.changed)
        changed = changed_path.read_text(encoding="utf-8").splitlines() if changed_path.is_file() else []
        files, skipped = select_tests(changed, args.cap)
        if not files:
            print("inga matchande tester")
            return 0
    else:
        files = sorted(TESTS.glob("*.php"))
    if not files:
        print("inga tester")
        return 1
    passed: list[str] = []
    known_fail: list[tuple[str, str]] = []
    known_green: list[str] = []
    blocking: list[tuple[str, str]] = []
    not_run: list[str] = list(skipped)
    started = time.monotonic()
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        elapsed = time.monotonic() - started
        if args.changed and elapsed >= args.budget:
            not_run.append(rel)
            continue
        per_test = TIMEOUT
        if args.changed:
            per_test = max(5, min(TIMEOUT, int(args.budget - elapsed)))
        try:
            proc = subprocess.run(
                ["php", str(path)],
                cwd=ROOT,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                timeout=per_test,
                check=False,
            )
            output = (proc.stdout or "") + (proc.stderr or "")
            code = proc.returncode
        except subprocess.TimeoutExpired as err:
            out = err.stdout or ""
            err_out = err.stderr or ""
            if isinstance(out, bytes):
                out = out.decode("utf-8", "replace")
            if isinstance(err_out, bytes):
                err_out = err_out.decode("utf-8", "replace")
            output = out + err_out + f"\nTIMEOUT efter {TIMEOUT}s\n"
            code = 124
        needles = known.get(rel) or []
        needle = " | ".join(needles)
        if code == 0:
            passed.append(rel)
            if needle:
                known_green.append(rel)
            continue
        tail = " ".join(output.split())
        if len(tail) > 400:
            tail = tail[-400:]
        if needles and known_matches(needles, output):
            known_fail.append((rel, needle))
            print(f"KÄND {rel}: {needle}")
            continue
        blocking.append((rel, tail))
        print(f"FEL {rel} (exit {code}): {tail}")
    summary = [
        f"körda {len(passed) + len(known_fail) + len(blocking)}",
        f"gröna {len(passed)}",
        f"kända fel {len(known_fail)}",
        f"oväntade fel {len(blocking)}",
        f"inte körda {len(not_run)}",
    ]
    print(" ".join(summary))
    if not_run:
        print("inte körda: " + ", ".join(not_run[:20]))
    if known_green:
        print("känd rad som var grön: " + ", ".join(known_green))
    step = os.environ.get("GITHUB_STEP_SUMMARY")
    if step:
        lines = ["### PHP-tester", "", " ".join(summary), ""]
        if known_fail:
            lines.append("Kända fel (fäller inte):")
            lines.extend(f"- `{rel}` — {needle}" for rel, needle in known_fail)
            lines.append("")
        if blocking:
            lines.append("Oväntade fel:")
            lines.extend(f"- `{rel}` — {tail}" for rel, tail in blocking)
            lines.append("")
        if not_run:
            lines.append("Inte körda (tak eller tidsbudget): " + ", ".join(f"`{rel}`" for rel in not_run[:20]))
        if known_green:
            lines.append("Känd rad som var grön: " + ", ".join(f"`{rel}`" for rel in known_green))
        with open(step, "a", encoding="utf-8") as handle:
            handle.write("\n".join(lines) + "\n")
    return 1 if blocking else 0


if __name__ == "__main__":
    sys.exit(main())
