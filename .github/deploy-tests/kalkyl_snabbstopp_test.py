#!/usr/bin/env python3
"""Torrkontroll av kalkyl-snabbstoppet. Ingen FTP-server, ingen kunddata."""

from __future__ import annotations

import os
import posixpath
import re
import sys
from ftplib import error_perm
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / ".github" / "scripts" / "kalkyl_snabbstopp.py"
WORKFLOW = REPO / ".github" / "workflows" / "kalkyl-snabbstopp.yml"
sys.path.insert(0, str(SCRIPT.parent))

import kalkyl_snabbstopp  # noqa: E402

EXPECTED_MOVES = (
    (
        "/kalkyl.jarfalla.example.test/public_html/lead",
        "/kalkyl.jarfalla.example.test/arkiv-2026-10-19/lead",
    ),
    (
        "/kalkyl.jarfalla.example.test/public_html/find",
        "/kalkyl.jarfalla.example.test/arkiv-2026-10-19/find",
    ),
    (
        "/api.kalkyl.jarfalla.example.test/public_html/.idea",
        "/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/.idea",
    ),
    (
        "/api.kalkyl.jarfalla.example.test/public_html/adminer.php",
        "/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/adminer.php",
    ),
    (
        "/api.kalkyl.jarfalla.example.test/public_html/un.php",
        "/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/un.php",
    ),
    (
        "/api.kalkyl.jarfalla.example.test/public_html/vendor.zip",
        "/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/vendor.zip",
    ),
)

EXPECTED_ALLOWED = frozenset(
    {
        "/kalkyl.jarfalla.example.test",
        "/kalkyl.jarfalla.example.test/public_html",
        "/kalkyl.jarfalla.example.test/public_html/lead",
        "/kalkyl.jarfalla.example.test/public_html/find",
        "/kalkyl.jarfalla.example.test/arkiv-2026-10-19",
        "/kalkyl.jarfalla.example.test/arkiv-2026-10-19/lead",
        "/kalkyl.jarfalla.example.test/arkiv-2026-10-19/find",
        "/api.kalkyl.jarfalla.example.test",
        "/api.kalkyl.jarfalla.example.test/public_html",
        "/api.kalkyl.jarfalla.example.test/public_html/.idea",
        "/api.kalkyl.jarfalla.example.test/public_html/adminer.php",
        "/api.kalkyl.jarfalla.example.test/public_html/un.php",
        "/api.kalkyl.jarfalla.example.test/public_html/vendor.zip",
        "/api.kalkyl.jarfalla.example.test/public_html/.htaccess",
        "/api.kalkyl.jarfalla.example.test/public_html/dashboard",
        "/api.kalkyl.jarfalla.example.test/public_html/dashboard/.htaccess",
        "/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19",
        "/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/.idea",
        "/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/adminer.php",
        "/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/un.php",
        "/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/vendor.zip",
        "/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/htaccess-api-root.original",
        "/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/htaccess-dashboard.original",
        "/api.kalkyl.jarfalla.example.test/private",
        "/api.kalkyl.jarfalla.example.test/private/.htpasswd",
    }
)

HOME = "/www/webvol29/ab/acct"
AUTH_FILE = HOME + "/api.kalkyl.jarfalla.example.test/private/.htpasswd"
SECRET = "drift:super-secret-value"


def fail(message: str) -> None:
    raise SystemExit(message)


def test_script_has_no_delete_and_only_fixed_paths() -> None:
    text = SCRIPT.read_text(encoding="utf-8")
    for verb in ("DELE", "RMD", "XRMD"):
        if re.search(rf"\b{verb}\b", text):
            fail(f"skriptet innehåller {verb}")
    if ".delete(" in text or ".rmd(" in text:
        fail("skriptet anropar radering")
    for verb in ("DELE", "RMD", "XRMD", "APPE"):
        if verb in kalkyl_snabbstopp.VERBS:
            fail(f"verblistan innehåller {verb}")
        try:
            kalkyl_snabbstopp.assert_verb(verb)
        except kalkyl_snabbstopp.StopError:
            continue
        fail(f"{verb} släpptes igenom")
    kalkyl_snabbstopp.assert_verb("RNFR")
    kalkyl_snabbstopp.assert_verb("RNTO")
    kalkyl_snabbstopp.assert_verb("MKD")
    kalkyl_snabbstopp.assert_verb("STOR")
    if kalkyl_snabbstopp.MOVES != EXPECTED_MOVES:
        fail("flyttlistan är ändrad")
    if kalkyl_snabbstopp.ALLOWED != EXPECTED_ALLOWED:
        missing = EXPECTED_ALLOWED - kalkyl_snabbstopp.ALLOWED
        extra = kalkyl_snabbstopp.ALLOWED - EXPECTED_ALLOWED
        fail(f"sökvägarna är ändrade, saknas {missing}, extra {extra}")
    for path in kalkyl_snabbstopp.ALLOWED:
        for fragment in ("/rot", "/src", "flowstack.example.test", "malmo-demo"):
            if fragment in path:
                fail(f"tillåten sökväg träffar {fragment}: {path}")
    for banned in (
        "/kalkyl.jarfalla.example.test/public_html/rot",
        "/kalkyl.jarfalla.example.test/public_html/src",
        "/flowstack.example.test/public_html",
        "/kalkyl.malmo.example.test/public_html",
    ):
        try:
            kalkyl_snabbstopp.guard_path(banned)
        except kalkyl_snabbstopp.StopError:
            continue
        fail(f"{banned} släpptes igenom")
    kalkyl_snabbstopp.guard_path(EXPECTED_MOVES[0][0])
    if "Rollback" not in text or "RNFR" not in text or "RNTO" not in text:
        fail("rollback saknas i skriptet")
    plan = "\n".join(kalkyl_snabbstopp.plan_lines())
    for src, dst in EXPECTED_MOVES:
        if f"MOVE {src} {dst}" not in plan:
            fail(f"planen saknar flytten {src}")
        if f"RNFR {dst}" not in plan or f"RNTO {src}" not in plan:
            fail(f"rollback saknar {dst}")
    if SECRET in plan or "super-secret-value" in text:
        fail("planen eller skriptet innehåller ett lösenord")


def test_workflow_is_manual_check_by_default() -> None:
    text = WORKFLOW.read_text(encoding="utf-8")
    if "workflow_dispatch" not in text:
        fail("workflow saknar workflow_dispatch")
    if re.search(r"(?m)^\s*push\s*:", text) or "pull_request" in text or "schedule:" in text:
        fail("workflow har en trigger utöver manuell körning")
    if "default: check" not in text or "apply" not in text:
        fail("mode saknar check som standard")
    if re.search(r"(?m)^\s+paths\s*:", text):
        fail("workflow tar emot sökvägar")
    for secret in ("LOOPIA_FTP_HOST", "LOOPIA_FTP_USER", "LOOPIA_FTP_PASS", "KALKYL_BASIC_AUTH"):
        if secret not in text:
            fail(f"workflow saknar {secret}")
    if "kalkyl_snabbstopp.py" not in text:
        fail("workflow saknar skriptet")
    if "contents: read" not in text or "persist-credentials: false" not in text:
        fail("workflow kan skriva till git")
    if "git push" in text or "git commit" in text:
        fail("workflow skriver till git")
    if re.search(r"(?i)password\s*[:=]\s*\S", text):
        fail("workflow innehåller ett lösenord")
    for verb in ("DELE", "RMD"):
        if re.search(rf"\b{verb}\b", text):
            fail(f"workflow innehåller {verb}")


def test_merge_keeps_existing_rules() -> None:
    original = b"RewriteEngine On\nRewriteRule ^contact$ index.php [L]\n"
    merged = kalkyl_snabbstopp.merge_root_htaccess(original, AUTH_FILE)
    text = merged.decode("latin-1")
    if "RewriteEngine On" not in text or "RewriteRule ^contact$ index.php [L]" not in text:
        fail("befintliga regler försvann")
    if text.find("RewriteEngine") > text.find(kalkyl_snabbstopp.MARKER_BEGIN):
        fail("blocket lades inte sist")
    if "AuthType Basic" not in text or f"AuthUserFile {AUTH_FILE}" not in text:
        fail("auth-blocket saknas")
    if "GET|DELETE" not in text or "/search-logs" not in text:
        fail("villkoret för search-logs saknas")
    if kalkyl_snabbstopp.merge_root_htaccess(merged, AUTH_FILE) != merged:
        fail("blocket lades till två gånger")
    dashboard = kalkyl_snabbstopp.dashboard_htaccess(AUTH_FILE).decode("ascii")
    if "AuthType Basic" not in dashboard or AUTH_FILE not in dashboard:
        fail("dashboard-filen saknar Basic Auth")
    if "super-secret-value" in text or "super-secret-value" in dashboard:
        fail("auth-filen innehåller lösenordet")


def test_expect_and_htpasswd() -> None:
    if not kalkyl_snabbstopp.expect_ok("404", 404) or kalkyl_snabbstopp.expect_ok("404", 200):
        fail("404-kontrollen är fel")
    if not kalkyl_snabbstopp.expect_ok("401", 401) or kalkyl_snabbstopp.expect_ok("401", 200):
        fail("401-kontrollen är fel")
    if not kalkyl_snabbstopp.expect_ok("200", 200) or kalkyl_snabbstopp.expect_ok("200", 401):
        fail("200-kontrollen är fel")
    if not kalkyl_snabbstopp.expect_ok("not-401", 200) or kalkyl_snabbstopp.expect_ok("not-401", 401):
        fail("POST-kontrollen är fel")
    if not kalkyl_snabbstopp.expect_ok("not-401-404", 200):
        fail("rot-kontrollen underkände 200")
    if kalkyl_snabbstopp.expect_ok("not-401-404", 401) or kalkyl_snabbstopp.expect_ok("not-401-404", 404):
        fail("rot-kontrollen släppte 401 eller 404")
    payload = kalkyl_snabbstopp.build_htpasswd(SECRET)
    if b"super-secret-value" in payload or b"drift:super-secret-value" in payload:
        fail("lösenordsfilen innehåller hemligheten")
    if not payload.startswith(b"drift:$2y$") or not payload.endswith(b"\n"):
        fail("lösenordsfilen är inte bcrypt")
    import crypt

    hashed = payload.decode("ascii").split(":", 1)[1].strip()
    if crypt.crypt("super-secret-value", hashed) != hashed:
        fail("bcrypt-hashen verifierar inte")
    try:
        kalkyl_snabbstopp.build_htpasswd("")
    except kalkyl_snabbstopp.StopError as err:
        if "KALKYL_BASIC_AUTH" not in str(err):
            fail("tomt secret ger otydligt fel")
    else:
        fail("tomt secret släpptes igenom")


def test_home_check_fails_closed() -> None:
    class HomeFTP:
        def __init__(self, pwd: str, names: list[str]) -> None:
            self._pwd = pwd
            self._names = names

        def pwd(self) -> str:
            return self._pwd

        def sendcmd(self, cmd: str) -> str:
            kalkyl_snabbstopp.assert_verb(cmd.split()[0])
            if cmd.split()[0].upper() != "PWD":
                raise error_perm("500")
            return f'257 "{self._pwd}" is current'

        def nlst(self) -> list[str]:
            return list(self._names)

        def voidcmd(self, cmd: str) -> str:
            kalkyl_snabbstopp.assert_verb(cmd.split()[0])
            return "200"

    try:
        kalkyl_snabbstopp.check_home(HomeFTP("/", ["kalkyl.jarfalla.example.test", "api.kalkyl.jarfalla.example.test"]))
    except kalkyl_snabbstopp.StopError as err:
        if "okänd" not in str(err):
            fail("okänd hem gav otydligt fel")
    else:
        fail("PWD / godtogs som hem")
    try:
        kalkyl_snabbstopp.check_home(HomeFTP(HOME, ["annat"]))
    except kalkyl_snabbstopp.StopError:
        pass
    else:
        fail("hem utan kalkylträd godtogs")
    found = kalkyl_snabbstopp.check_home(
        HomeFTP(HOME, ["kalkyl.jarfalla.example.test", "api.kalkyl.jarfalla.example.test"])
    )
    if found != HOME:
        fail("hemkatalogen lästes fel")
    if kalkyl_snabbstopp.auth_user_file(found) != AUTH_FILE:
        fail("AuthUserFile pekar fel")


class FakeFTP:
    """Minnes-FTP. Saknar radering."""

    def __init__(self, dirs: set[str], files: dict[str, bytes]) -> None:
        self.dirs = set(dirs)
        self.files = dict(files)
        self.cwd_path = "/"
        self.commands: list[str] = []
        self._rnfr = ""

    def pwd(self) -> str:
        return self.cwd_path

    def cwd(self, path: str) -> None:
        self.commands.append("CWD " + path)
        target = path.rstrip("/") or "/"
        if target != "/" and target not in self.dirs:
            raise error_perm("550")
        self.cwd_path = target

    def sendcmd(self, cmd: str) -> str:
        self.commands.append(cmd)
        verb, _, arg = cmd.partition(" ")
        verb = verb.upper()
        kalkyl_snabbstopp.assert_verb(verb)
        if verb == "RNFR":
            self._rnfr = arg.strip()
            if self._rnfr not in self.dirs and self._rnfr not in self.files:
                raise error_perm("550")
            return "350"
        if verb == "RNTO":
            dst = arg.strip()
            src = self._rnfr
            if dst in self.dirs or dst in self.files:
                raise error_perm("550")
            if src in self.files:
                self.files[dst] = self.files.pop(src)
            else:
                self._move_dir(src, dst)
            return "250"
        if verb == "TYPE":
            return "200"
        raise error_perm("500")

    def _move_dir(self, src: str, dst: str) -> None:
        prefix = src.rstrip("/") + "/"
        self.dirs.discard(src)
        self.dirs.add(dst)
        for directory in [item for item in self.dirs if item.startswith(prefix)]:
            self.dirs.discard(directory)
            self.dirs.add(dst.rstrip("/") + "/" + directory[len(prefix) :])
        for name in [item for item in self.files if item.startswith(prefix)]:
            self.files[dst.rstrip("/") + "/" + name[len(prefix) :]] = self.files.pop(name)

    def mkd(self, name: str) -> str:
        self.commands.append("MKD " + name)
        path = posixpath.normpath(posixpath.join(self.cwd_path, name))
        self.dirs.add(path)
        return path

    def size(self, name: str) -> int:
        path = posixpath.join(self.cwd_path, name)
        if path not in self.files:
            raise error_perm("550")
        return len(self.files[path])

    def nlst(self) -> list[str]:
        base = self.cwd_path.rstrip("/") or "/"
        names = []
        for directory in self.dirs:
            if posixpath.dirname(directory) == base:
                names.append(posixpath.basename(directory))
        for name in self.files:
            if posixpath.dirname(name) == base:
                names.append(posixpath.basename(name))
        return names

    def voidcmd(self, cmd: str) -> str:
        return self.sendcmd(cmd)

    def retrbinary(self, cmd: str, callback, blocksize: int = 8192) -> None:
        self.commands.append(cmd)
        if not cmd.startswith("RETR "):
            raise error_perm("500")
        path = posixpath.join(self.cwd_path, cmd.split(" ", 1)[1])
        payload = self.files.get(path)
        if payload is None:
            raise error_perm("550")
        callback(payload)

    def storbinary(self, cmd: str, fp, blocksize: int = 8192) -> None:
        self.commands.append(cmd)
        if not cmd.startswith("STOR "):
            raise error_perm("500")
        path = posixpath.join(self.cwd_path, cmd.split(" ", 1)[1])
        self.files[path] = fp.read()


def tree() -> tuple[set[str], dict[str, bytes]]:
    dirs = {
        "/kalkyl.jarfalla.example.test",
        "/kalkyl.jarfalla.example.test/public_html",
        "/kalkyl.jarfalla.example.test/public_html/lead",
        "/kalkyl.jarfalla.example.test/public_html/find",
        "/api.kalkyl.jarfalla.example.test",
        "/api.kalkyl.jarfalla.example.test/public_html",
        "/api.kalkyl.jarfalla.example.test/public_html/.idea",
        "/api.kalkyl.jarfalla.example.test/public_html/dashboard",
        "/api.kalkyl.jarfalla.example.test/private",
    }
    files = {
        "/kalkyl.jarfalla.example.test/public_html/lead/index.php": b"<?php\n",
        "/api.kalkyl.jarfalla.example.test/public_html/.idea/workspace.xml": b"<project/>\n",
        "/api.kalkyl.jarfalla.example.test/public_html/adminer.php": b"<?php echo 'admin';\n",
        "/api.kalkyl.jarfalla.example.test/public_html/un.php": b"<?php echo 'no';\n",
        "/api.kalkyl.jarfalla.example.test/public_html/vendor.zip": b"PK\x03\x04",
        "/api.kalkyl.jarfalla.example.test/public_html/.htaccess": (
            b"RewriteEngine On\nRewriteRule ^contact$ index.php [L]\n"
        ),
    }
    return dirs, files


def assert_clean(commands: list[str]) -> None:
    joined = "\n".join(commands)
    for verb in ("DELE", "RMD", "XRMD"):
        if re.search(rf"(^|\n){verb}\b", joined):
            fail(f"fejkservern fick {verb}")
    for command in commands:
        if command.startswith("CWD "):
            path = command.split(" ", 1)[1]
            if path not in EXPECTED_ALLOWED and path != "/":
                fail(f"CWD utanför listan: {path}")
        if command.startswith(("RNFR ", "RNTO ")):
            path = command.split(" ", 1)[1]
            if path not in EXPECTED_ALLOWED:
                fail(f"flytt utanför listan: {path}")


def test_apply_moves_and_skips_existing_target() -> None:
    dirs, files = tree()
    ftp = FakeFTP(dirs, files)
    payload = kalkyl_snabbstopp.build_htpasswd(SECRET)
    kalkyl_snabbstopp.execute(ftp, HOME, payload)
    assert_clean(ftp.commands)
    if "/kalkyl.jarfalla.example.test/public_html/lead" in ftp.dirs:
        fail("lead ligger kvar publikt")
    if "/kalkyl.jarfalla.example.test/arkiv-2026-10-19/lead/index.php" not in ftp.files:
        fail("lead flyttades inte till arkivet")
    if "/api.kalkyl.jarfalla.example.test/public_html/adminer.php" in ftp.files:
        fail("adminer.php ligger kvar publikt")
    if ftp.files.get("/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/adminer.php") != b"<?php echo 'admin';\n":
        fail("adminer.php ändrades vid flytten")
    root = ftp.files["/api.kalkyl.jarfalla.example.test/public_html/.htaccess"].decode("latin-1")
    if "RewriteRule ^contact$ index.php [L]" not in root or "AuthType Basic" not in root:
        fail("api-rotens .htaccess tappade en regel eller auth")
    if AUTH_FILE not in root:
        fail("AuthUserFile saknar den absoluta sökvägen")
    backup = ftp.files["/api.kalkyl.jarfalla.example.test/arkiv-2026-10-19/htaccess-api-root.original"]
    if backup != files["/api.kalkyl.jarfalla.example.test/public_html/.htaccess"]:
        fail("backupen är inte originalet")
    dashboard = ftp.files["/api.kalkyl.jarfalla.example.test/public_html/dashboard/.htaccess"]
    if b"AuthType Basic" not in dashboard or AUTH_FILE.encode() not in dashboard:
        fail("dashboard saknar Basic Auth")
    stored = ftp.files["/api.kalkyl.jarfalla.example.test/private/.htpasswd"]
    if stored != payload or b"super-secret-value" in stored:
        fail("lösenordsfilen skrevs fel")
    if any("super-secret-value" in command for command in ftp.commands):
        fail("FTP-kommandot innehåller hemligheten")
    again = FakeFTP(set(ftp.dirs), dict(ftp.files))
    kalkyl_snabbstopp.execute(again, HOME, payload)
    assert_clean(again.commands)
    if sum(1 for command in again.commands if command.startswith("RNFR ")) != 0:
        fail("andra körningen flyttade om ett mål som redan fanns")
    if root.count(kalkyl_snabbstopp.MARKER_BEGIN) != 1:
        fail("auth-blocket dubbleras")
    skipped = tree()
    skipped[0].add("/kalkyl.jarfalla.example.test/arkiv-2026-10-19")
    skipped[0].add("/kalkyl.jarfalla.example.test/arkiv-2026-10-19/lead")
    partial = FakeFTP(*skipped)
    kalkyl_snabbstopp.execute(partial, HOME, payload)
    assert_clean(partial.commands)
    if "/kalkyl.jarfalla.example.test/public_html/lead" not in partial.dirs:
        fail("ett befintligt mål skrevs över")
    if "/kalkyl.jarfalla.example.test/public_html/find" in partial.dirs:
        fail("find skulle flyttas när målet saknades")


def test_check_does_not_connect() -> None:
    def boom(*_args, **_kwargs):
        raise AssertionError("check anslöt")

    original = kalkyl_snabbstopp.connect
    kalkyl_snabbstopp.connect = boom
    saved = os.environ.get("KALKYL_BASIC_AUTH")
    os.environ.pop("KALKYL_BASIC_AUTH", None)
    try:
        code = kalkyl_snabbstopp.main(["--mode", "check"])
        if code != 0:
            fail("check föll")
        try:
            kalkyl_snabbstopp.main(["--mode", "apply"])
        except kalkyl_snabbstopp.StopError as err:
            if "KALKYL_BASIC_AUTH" not in str(err):
                fail("apply utan secret ger otydligt fel")
        else:
            fail("apply utan secret anslöt eller fortsatte")
        try:
            kalkyl_snabbstopp.main(["--root", "/flowstack.example.test/public_html"])
        except SystemExit:
            pass
        else:
            fail("skriptet tar emot en sökväg")
    finally:
        kalkyl_snabbstopp.connect = original
        if saved is None:
            os.environ.pop("KALKYL_BASIC_AUTH", None)
        else:
            os.environ["KALKYL_BASIC_AUTH"] = saved


def test_verify_http_prints_only_codes() -> None:
    statuses = {
        ("GET", "https://kalkyl.jarfalla.example.test/lead/"): 404,
        ("GET", "https://kalkyl.jarfalla.example.test/find/"): 404,
        ("GET", "https://api.kalkyl.jarfalla.example.test/.idea/workspace.xml"): 404,
        ("GET", "https://api.kalkyl.jarfalla.example.test/adminer.php"): 404,
        ("GET", "https://api.kalkyl.jarfalla.example.test/dashboard/"): 401,
        ("GET", "https://api.kalkyl.jarfalla.example.test/search-logs"): 401,
        ("POST", "https://api.kalkyl.jarfalla.example.test/search-logs"): 422,
        ("GET", "https://kalkyl.jarfalla.example.test/rot/action_page.php"): 200,
        ("GET", "https://kalkyl.jarfalla.example.test/"): 200,
        ("GET", "https://kalkyl.malmo.example.test/"): 200,
    }

    def fetch(method: str, url: str, headers: tuple[tuple[str, str], ...]) -> int:
        if method == "GET" and url.endswith("/search-logs"):
            if headers != (("X-API-Version", "1"),):
                fail("GET /search-logs saknar X-API-Version")
        return statuses[(method, url)]

    import io
    from contextlib import redirect_stdout

    buffer = io.StringIO()
    with redirect_stdout(buffer):
        kalkyl_snabbstopp.verify_http(fetch)
    printed = buffer.getvalue().split()
    if printed != ["404", "404", "404", "404", "401", "401", "422", "200", "200", "200"]:
        fail("utskriften är inte bara statuskoderna: " + buffer.getvalue())
    statuses[("POST", "https://api.kalkyl.jarfalla.example.test/search-logs")] = 401
    try:
        with redirect_stdout(io.StringIO()):
            kalkyl_snabbstopp.verify_http(fetch)
    except kalkyl_snabbstopp.StopError:
        pass
    else:
        fail("POST 401 godtogs")


def main() -> None:
    test_script_has_no_delete_and_only_fixed_paths()
    test_workflow_is_manual_check_by_default()
    test_merge_keeps_existing_rules()
    test_expect_and_htpasswd()
    test_home_check_fails_closed()
    test_apply_moves_and_skips_existing_target()
    test_check_does_not_connect()
    test_verify_http_prints_only_codes()
    print("kalkyl snabbstopp: ok")


if __name__ == "__main__":
    main()
