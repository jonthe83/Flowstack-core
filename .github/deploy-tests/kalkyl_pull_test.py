#!/usr/bin/env python3
"""Torrkontroll av kalkyl-hämtningen. Ingen riktig FTP-server, ingen kunddata."""

from __future__ import annotations

import posixpath
import re
import shutil
import sys
import tempfile
from pathlib import Path

from ftplib import error_perm

REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / ".github" / "scripts" / "kalkyl_pull.py"
WORKFLOW = REPO / ".github" / "workflows" / "kalkyl-pull.yml"
sys.path.insert(0, str(SCRIPT.parent))

import kalkyl_pull  # noqa: E402

FORBIDDEN = ("STOR", "DELE", "RNFR", "MKD", "RMD")
GOOGLE = "AIza" + ("A" * 31) + "1234"


def fail(message: str) -> None:
    raise SystemExit(message)


def test_script_has_no_write_verbs() -> None:
    text = SCRIPT.read_text(encoding="utf-8")
    for verb in FORBIDDEN:
        if re.search(rf"\b{verb}\b", text):
            fail(f"skriptet innehåller FTP-skrivkommandot {verb}")
    for verb in (*FORBIDDEN, "RNTO", "APPE", "STOU"):
        if verb in kalkyl_pull.READ_VERBS:
            fail(f"läslistan innehåller {verb}")
        try:
            kalkyl_pull.assert_read_only_command(f"{verb} namn")
        except kalkyl_pull.PullError:
            continue
        fail(f"{verb} släpptes igenom")
    kalkyl_pull.assert_read_only_command("RETR index.php")
    kalkyl_pull.assert_read_only_command("CWD public_html")
    kalkyl_pull.assert_read_only_command("LIST")


def test_workflow_is_fixed_and_read_only() -> None:
    text = WORKFLOW.read_text(encoding="utf-8")
    if "workflow_dispatch" not in text:
        fail("workflow saknar workflow_dispatch")
    if re.search(r"(?m)^[\s]*push\s*:", text) or "pull_request" in text:
        fail("workflow har en trigger utöver manuell körning")
    if "inputs:" in text:
        fail("rötterna får inte vara inputs")
    for verb in FORBIDDEN:
        if re.search(rf"\b{verb}\b", text):
            fail(f"workflow innehåller {verb}")
    for secret in ("LOOPIA_FTP_HOST", "LOOPIA_FTP_USER", "LOOPIA_FTP_PASS"):
        if secret not in text:
            fail(f"workflow saknar {secret}")
    if "upload-artifact" not in text or "kalkyl_pull.py" not in text:
        fail("workflow saknar artefakt eller skript")
    if "TINYFM" in text or "git push" in text or "git commit" in text:
        fail("workflow kan skriva utanför artefaktet")
    if "contents: read" not in text or "persist-credentials: false" not in text:
        fail("workflow kan skriva till git")
    if kalkyl_pull.ROOTS != (
        "/api.kalkyl.jarfalla.example.test/public_html",
        "/kalkyl.jarfalla.example.test/public_html",
        "/kalkyl.malmo.example.test/public_html",
    ):
        fail("rötterna är ändrade")
    script = SCRIPT.read_text(encoding="utf-8")
    if "--root" in script or "LOOPIA_FTP_ROOT" in script:
        fail("skriptet tar emot en rot")


def test_exclusion_rules() -> None:
    api = kalkyl_pull.API_ROOT
    other = kalkyl_pull.ROOTS[1]
    checks = (
        (api, "vendor", True, "vendor"),
        (api, "lib/vendor", True, "vendor"),
        (other, "vendor", True, None),
        (api, "private/a.php", False, "private"),
        (other, "Private/a.php", False, "private"),
        (api, ".env", False, ".env"),
        (api, ".env.local", False, ".env"),
        (api, "cert.key", False, "*.key"),
        (api, "config/secret", False, "config/secret"),
        (api, "config/secret/a.php", False, "config/secret"),
        (api, "config/app.php", False, None),
    )
    for root, rel, is_dir, expected in checks:
        got = kalkyl_pull.exclusion(root, rel, is_dir)
        if got != expected:
            fail(f"undantag {rel}: {got} != {expected}")


def test_mask_and_gitleaks() -> None:
    php = "\n".join(
        [
            "<?php",
            f"$apiKey = '{GOOGLE}';",
            "$password = 'hunter22';",
            "$mail->Password = 'smtp-hemlighet';",
            "$mail->Host = 'smtp.example.com';",
            "$name = 'Sam';",
            "define('SUPABASE_KEY', 'sb_secret_abcdefghijklmnopqrstuvwxyz');",
            "$dsn = 'mysql://app:supersecret@db.internal.example/app';",
            "$token = getenv('TOKEN');",
            "'service_role' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJyb2xlIjoiYW5vbiJ9.sig',",
        ]
    )
    masked = kalkyl_pull.mask_text(php)
    if kalkyl_pull.find_unmasked(masked):
        fail("maskerad PHP har kvar träffar: " + str(kalkyl_pull.find_unmasked(masked)))
    for secret in (GOOGLE, "hunter22", "smtp-hemlighet", "sb_secret_abcdefghijklmnopqrstuvwxyz", "supersecret"):
        if secret in masked:
            fail(f"värdet finns kvar efter maskering ({secret[:8]}…)")
    if "smtp.example.com" not in masked or "Sam" not in masked:
        fail("vanlig text maskerades")
    if "$token = getenv('TOKEN');" not in masked:
        fail("anrop utan literal maskerades")
    compared = "<?php if ($password === 'hunter22') { echo 'nej'; }\n"
    compared_masked = kalkyl_pull.mask_text(compared)
    if "hunter22" in compared_masked or kalkyl_pull.find_unmasked(compared_masked):
        fail("jämförelse med lösenord maskerades inte")
    supabase_url = "$supabaseUrl = 'https://abc.supabase.co';\n"
    if kalkyl_pull.mask_text(supabase_url) != supabase_url:
        fail("supabase-adress maskerades som nyckel")
    js = 'const apiKey = "abcd1234efgh";\nconst title = "Kontakt";\n'
    js_masked = kalkyl_pull.mask_text(js)
    if "abcd1234efgh" in js_masked or kalkyl_pull.find_unmasked(js_masked):
        fail("JS-nyckel maskerades inte")
    if "Kontakt" not in js_masked:
        fail("JS-titel maskerades")
    html = '<input type="password" value="hunter22"><input value="synlig" type="text">'
    html_masked = kalkyl_pull.mask_text(html)
    if "hunter22" in html_masked or "synlig" not in html_masked:
        fail("HTML-lösenord maskerades fel")
    raw = f"const apiKey = '{GOOGLE}';"
    if not kalkyl_pull.find_unmasked(raw):
        fail("omaskerad nyckel slank igenom kontrollen")


def test_contact_report_hides_addresses() -> None:
    index = """<?php
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/contact'
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/contact_mail.php';
    send_contact();
}
"""
    mailer = """<?php
function send_contact(): void {
    $dsn = 'mysql://app:supersecret@db.internal.example/app';
    mail('kund@example.com', 'Hej', $body);
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.example.com';
    $ch = curl_init('https://abc.supabase.co/rest/v1/leads');
}
"""
    sources = {"index.php": index, "contact_mail.php": mailer}
    contact = kalkyl_pull.analyze_contact(sources)
    report = kalkyl_pull.PullReport(files=["api.kalkyl.jarfalla.example.test/public_html/index.php"], contact=contact)
    text = kalkyl_pull.render_report(report)
    if "@" in text or "supersecret" in text or "kund" in text:
        fail("rapporten läcker en adress eller ett lösenord")
    for host in ("db.internal.example", "smtp.example.com", "abc.supabase.co"):
        if host not in text:
            fail(f"värden {host} saknas")
    for call in ("mail", "PHPMailer", "SMTP", "Supabase", "curl"):
        if call not in text:
            fail(f"anrop {call} saknas")
    if "index.php" not in text or "contact_mail.php" not in text:
        fail("hanteraren saknas")
    empty = kalkyl_pull.analyze_contact({"index.php": "<?php echo 'hej';\n"})
    if empty.route_files:
        fail("en sida utan route tolkades som POST /contact")


class FakeFTP:
    """Katalogträd i minnet. Saknar skrivmetoder."""

    def __init__(self, nodes: dict[str, bytes | None]) -> None:
        self.nodes = nodes
        self.cwd_path = "/"
        self.retrieved: list[str] = []

    def cwd(self, path: str) -> None:
        if path == "/":
            self.cwd_path = "/"
            return
        if path.startswith("/"):
            target = path.rstrip("/") or "/"
        else:
            target = posixpath.normpath(posixpath.join(self.cwd_path, path))
        if target != "/" and target not in self.nodes:
            raise error_perm("550")
        if target != "/" and self.nodes[target] is not None:
            raise error_perm("550")
        self.cwd_path = target

    def pwd(self) -> str:
        return self.cwd_path

    def mlsd(self, path: str = "", facts: list[str] | None = None):
        base = self.cwd_path.rstrip("/") or "/"
        children = []
        for key, payload in self.nodes.items():
            if posixpath.dirname(key.rstrip("/")) == base:
                name = posixpath.basename(key)
                kind = "dir" if payload is None else "file"
                children.append((name, {"type": kind}))
        return children

    def retrbinary(self, cmd: str, callback, blocksize: int = 8192) -> None:
        if not cmd.startswith("RETR "):
            raise error_perm("500")
        name = cmd.split(" ", 1)[1]
        path = posixpath.join(self.cwd_path, name)
        payload = self.nodes.get(path)
        if not isinstance(payload, bytes):
            raise error_perm("550")
        self.retrieved.append(path)
        callback(payload)

    def voidcmd(self, cmd: str) -> str:
        kalkyl_pull.assert_read_only_command(cmd)
        return "200"

    def size(self, name: str) -> int:
        path = posixpath.join(self.cwd_path, name)
        payload = self.nodes.get(path)
        if not isinstance(payload, bytes):
            raise error_perm("550")
        return len(payload)

    def close(self) -> None:
        return None


def fixture_tree() -> dict[str, bytes | None]:
    api = "/api.kalkyl.jarfalla.example.test/public_html"
    jarfalla = "/kalkyl.jarfalla.example.test/public_html"
    malmo = "/kalkyl.malmo.example.test/public_html"
    index = """<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['PATH'] ?? '') === '/contact') {
    require __DIR__ . '/lib/send.php';
}
""".encode()
    send = f"""<?php
function send_contact(): void {{
    $apiKey = '{GOOGLE}';
    curl_init('https://abc.supabase.co/rest/v1/leads');
}}
""".encode()
    app = b"""<?php
$password = 'hunter22';
"""
    html = b"<p>Hej</p>\n"
    js = b"const title = 'Malmo';\nconst token = 'abcdefghijklmnop';\n"
    nodes: dict[str, bytes | None] = {
        api: None,
        f"{api}/index.php": index,
        f"{api}/lib": None,
        f"{api}/lib/send.php": send,
        f"{api}/config": None,
        f"{api}/config/app.php": app,
        f"{api}/config/secret": None,
        f"{api}/config/secret/hidden.php": b"<?php $password = 'inne-i-secret';\n",
        f"{api}/vendor": None,
        f"{api}/vendor/autoload.php": b"<?php $password = 'vendor-secret';\n",
        f"{api}/private": None,
        f"{api}/private/note.php": b"<?php $token = 'private-secret';\n",
        f"{api}/.env": b"API_KEY=should-not-download\n",
        f"{api}/cert.key": b"-----BEGIN PRIVATE KEY-----\nsecret\n",
        jarfalla: None,
        f"{jarfalla}/index.html": html,
        f"{jarfalla}/vendor": None,
        f"{jarfalla}/vendor/keep.txt": b"behalls\n",
        malmo: None,
        f"{malmo}/app.js": js,
        f"{malmo}/private": None,
        f"{malmo}/private/x.js": b"const password = 'nope';\n",
    }
    # config/secret as a directory is represented by secret-dir plus a nested file.
    nodes[f"{api}/config/secret-dir"] = None
    return nodes


def test_download_excludes_and_masks() -> None:
    ftp = FakeFTP(fixture_tree())
    tmp = Path(tempfile.mkdtemp(prefix="kalkyl-pull-"))
    try:
        report = kalkyl_pull.download_roots(ftp, tmp)  # type: ignore[arg-type]
        text = (tmp / "REPORT.txt").read_text(encoding="utf-8")
        joined = "\n".join(path.read_text(encoding="utf-8") for path in tmp.rglob("*") if path.is_file())
        for secret in (GOOGLE, "hunter22", "abcdefghijklmnop", "vendor-secret", "private-secret", "inne-i-secret", "should-not-download"):
            if secret in joined:
                fail("artefaktet innehåller ett omaskerat eller överhoppat värde")
        if "***MASKED***" not in (tmp / "api.kalkyl.jarfalla.example.test/public_html/config/app.php").read_text(encoding="utf-8"):
            fail("config/app.php maskerades inte")
        if "***MASKED***" not in (tmp / "kalkyl.malmo.example.test/public_html/app.js").read_text(encoding="utf-8"):
            fail("JS maskerades inte")
        downloaded = {path for path in ftp.retrieved}
        for banned in (
            "/api.kalkyl.jarfalla.example.test/public_html/vendor/autoload.php",
            "/api.kalkyl.jarfalla.example.test/public_html/private/note.php",
            "/api.kalkyl.jarfalla.example.test/public_html/.env",
            "/api.kalkyl.jarfalla.example.test/public_html/cert.key",
            "/api.kalkyl.jarfalla.example.test/public_html/config/secret/hidden.php",
            "/kalkyl.malmo.example.test/public_html/private/x.js",
        ):
            if banned in downloaded:
                fail(f"hämtade överhoppad fil {banned}")
        if "/kalkyl.jarfalla.example.test/public_html/vendor/keep.txt" not in downloaded:
            fail("vendor utanför api.kalkyl skulle hämtas")
        if "abc.supabase.co" not in text or "curl" not in text:
            fail("kontaktrapporten saknar curl-värd")
        if "@" in text:
            fail("kontaktrapporten innehåller en adress")
        if "index.php" not in text or "lib/send.php" not in text:
            fail("filerna saknas i rapporten")
        if not report.files:
            fail("tom fillista")
    finally:
        shutil.rmtree(tmp)


def main() -> None:
    test_script_has_no_write_verbs()
    test_workflow_is_fixed_and_read_only()
    test_exclusion_rules()
    test_mask_and_gitleaks()
    test_contact_report_hides_addresses()
    test_download_excludes_and_masks()
    print("kalkyl pull: ok")


if __name__ == "__main__":
    main()
