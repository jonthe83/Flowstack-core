#!/usr/bin/env python3
"""Lokal övning av Loopia-deployn. Pratar bara med pyftpdlib och php -S.

Visar att ett avbrott mitt i STOR lämnar live-filen orörd, att bytet går via
.bak, att grön grind tar bort .bak, .uploading och smoke, att 5xx och 200 utan
grindtext återställer utan att röra andras skräp, och att Loopia-block (403)
lämnar .bak kvar.
"""

from __future__ import annotations

import hashlib
import importlib.util
import logging
import os
import posixpath
import shutil
import socket
import subprocess
import sys
import tempfile
import textwrap
import threading
import time
import urllib.error
import urllib.request
from pathlib import Path

from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import TLS_DTPHandler, TLS_FTPHandler
from pyftpdlib.ioloop import IOLoop
from pyftpdlib.servers import FTPServer

REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / ".github" / "scripts" / "loopia_deploy.py"
WORKFLOW = REPO / ".github" / "workflows" / "deploy-loopia.yml"

OLD = b"OLD-LIVE-CONTENT\n" * 80
NEW = b"NEW-LIVE-CONTENT-FROM-MAIN\n" * 120
GONE = b"do-not-delete-this\n"
PASSWORD = "Kp7-loopia-test-secret"
USER = "ftp-user-jeab-test"

logging.getLogger("pyftpdlib").setLevel(logging.CRITICAL)
logging.disable(logging.INFO)


def load_module():
    spec = importlib.util.spec_from_file_location("loopia_deploy", SCRIPT)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"kan inte ladda {SCRIPT}")
    module = importlib.util.module_from_spec(spec)
    sys.modules["loopia_deploy"] = module
    spec.loader.exec_module(module)
    return module


def free_port() -> int:
    sock = socket.socket()
    sock.bind(("127.0.0.1", 0))
    port = sock.getsockname()[1]
    sock.close()
    return port


def run(cmd: list[str], cwd: Path | None = None) -> None:
    subprocess.run(cmd, cwd=cwd, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)


def make_git_repo(root: Path) -> str:
    run(["git", "init", "-b", "main", str(root)])
    run(["git", "-C", str(root), "config", "user.email", "deploy-test@example.com"])
    run(["git", "-C", str(root), "config", "user.name", "Deploy Test"])
    (root / "src").mkdir()
    (root / "tests").mkdir()
    (root / "data" / ".private").mkdir(parents=True)
    (root / "schema").mkdir()
    (root / ".github" / "workflows").mkdir(parents=True)
    (root / "src" / "ordrar.php").write_bytes(OLD)
    (root / "gone.txt").write_bytes(GONE)
    (root / "README.md").write_text("gammal\n", encoding="utf-8")
    (root / "tests" / "hidden.php").write_text("<?php\n", encoding="utf-8")
    (root / "data" / "passwords.json").write_text('{"secret":"nope"}\n', encoding="utf-8")
    (root / "data" / ".private" / "tinyfm_allowlist.txt").write_text("203.0.113.10/32\n", encoding="utf-8")
    (root / "data" / ".private" / "passwords.json").write_text('{"secret":"nope"}\n', encoding="utf-8")
    (root / "schema" / "mysql.php").write_text("<?php\n", encoding="utf-8")
    (root / ".github" / "workflows" / "nope.yml").write_text("name: no\n", encoding="utf-8")
    (root / "notes.md").write_text("anteckning\n", encoding="utf-8")
    run(["git", "-C", str(root), "add", "."])
    run(["git", "-C", str(root), "commit", "-m", "before"])
    (root / "src" / "ordrar.php").write_bytes(NEW)
    (root / "gone.txt").unlink()
    (root / "README.md").write_text("ny\n", encoding="utf-8")
    (root / "tests" / "hidden.php").write_text("<?php // changed\n", encoding="utf-8")
    (root / "deploy-check.txt").write_bytes(NEW[:64])
    (root / "notes.md").write_text("ny anteckning\n", encoding="utf-8")
    (root / "data" / "passwords.json").write_text('{"secret":"changed"}\n', encoding="utf-8")
    (root / "schema" / "mysql.php").write_text("<?php // changed\n", encoding="utf-8")
    (root / ".github" / "workflows" / "nope.yml").write_text("name: changed\n", encoding="utf-8")
    run(["git", "-C", str(root), "add", "-A"])
    run(["git", "-C", str(root), "commit", "-m", "after"])
    return subprocess.check_output(["git", "-C", str(root), "rev-parse", "HEAD"], text=True).strip()


def make_cert(directory: Path) -> tuple[Path, Path]:
    key = directory / "key.pem"
    cert = directory / "cert.pem"
    subprocess.run(
        [
            "openssl",
            "req",
            "-x509",
            "-newkey",
            "rsa:2048",
            "-keyout",
            str(key),
            "-out",
            str(cert),
            "-days",
            "1",
            "-nodes",
            "-subj",
            "/CN=127.0.0.1",
        ],
        check=True,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    return cert, key


class GuardDTP(TLS_DTPHandler):
    """TLS anropar DTPHandler.handle_read via handle_read_event, inte en override av handle_read."""

    def handle_read_event(self) -> None:
        cmd = self.cmd_channel
        file_obj = self.file_obj
        target = os.path.basename(getattr(file_obj, "name", "") or "")
        if (
            getattr(cmd, "abort_tmp", False)
            and ".tmp" in target
            and file_obj is not None
            and not getattr(file_obj, "_abort_wrapped", False)
        ):
            original = file_obj.write
            sent = {"done": False}

            def write(data: bytes) -> int:
                if not sent["done"]:
                    original(data[:8])
                    file_obj.flush()
                    sent["done"] = True
                try:
                    cmd.respond("426 Connection closed, transfer aborted.")
                except Exception:
                    pass
                self.close()
                return len(data)

            file_obj.write = write  # type: ignore[method-assign]
            file_obj._abort_wrapped = True
        if (
            getattr(cmd, "corrupt_tmp", False)
            and ".tmp" in target
            and file_obj is not None
            and not getattr(file_obj, "_corrupt_wrapped", False)
        ):
            original = file_obj.write

            def write(data: bytes) -> int:
                return original(data + b"\xff")

            file_obj.write = write  # type: ignore[method-assign]
            file_obj._corrupt_wrapped = True
        super().handle_read_event()


def make_handler(cert: Path, key: Path, home: Path):
    authorizer = DummyAuthorizer()
    authorizer.add_user(USER, PASSWORD, str(home), perm="elradfmwMT")

    class Guard(TLS_FTPHandler):
        stor_log: list[str] = []
        rename_log: list[tuple[str, str, str]] = []
        abort_tmp = False
        corrupt_tmp = False
        reject_overwrite = False

        def ftp_STOR(self, file, mode="w"):
            base = os.path.basename(file)
            self.stor_log.append(base)
            if ".tmp" not in base and base != "deploying.flag":
                self.respond("550 refusing STOR on a live name")
                return None
            return super().ftp_STOR(file, mode)

        def ftp_RNTO(self, path):
            src = self._rnfr
            src_name = src or ""
            dst_name = path
            if self.reject_overwrite and src and self.fs.lexists(path):
                self._rnfr = None
                self.rename_log.append((os.path.basename(src_name), os.path.basename(dst_name), "rejected"))
                self.respond("553 Won't overwrite existing file.")
                return None
            result = super().ftp_RNTO(path)
            self.rename_log.append((src_name, dst_name, "ok" if result else "fail"))
            return result

    Guard.authorizer = authorizer
    Guard.certfile = str(cert)
    Guard.keyfile = str(key)
    Guard.tls_control_required = True
    Guard.tls_data_required = True
    Guard.dtp_handler = GuardDTP
    Guard.ssl_context = None
    return Guard


class FtpWorld:
    def __init__(self, home: Path, handler) -> None:
        self.home = home
        self.handler = handler
        self.server = FTPServer(("127.0.0.1", 0), handler, ioloop=IOLoop.factory())
        self.port = self.server.socket.getsockname()[1]
        self.thread = threading.Thread(
            target=self.server.serve_forever,
            kwargs={"timeout": 0.2, "blocking": True, "handle_exit": False},
            daemon=True,
        )
        self.thread.start()
        time.sleep(0.1)

    def close(self) -> None:
        try:
            self.server.close_all()
        except Exception:
            pass
        self.thread.join(timeout=2)


class PhpWorld:
    def __init__(self, docroot: Path, router: Path, mode: str = "ok") -> None:
        self.port = free_port()
        handle, name = tempfile.mkstemp(prefix="jeab-gate-hits-", suffix=".txt")
        os.close(handle)
        self.hits = Path(name)
        self.hits.write_text("0", encoding="utf-8")
        env = os.environ.copy()
        env["JEAB_GATE_MODE"] = mode
        env["JEAB_GATE_HITS"] = str(self.hits)
        self.proc = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{self.port}", "-t", str(docroot), str(router)],
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )
        deadline = time.time() + 5
        last = ""
        while time.time() < deadline:
            try:
                with urllib.request.urlopen(f"http://127.0.0.1:{self.port}/index.php", timeout=0.3) as response:
                    response.read()
                self.hits.write_text("0", encoding="utf-8")
                return
            except urllib.error.HTTPError:
                self.hits.write_text("0", encoding="utf-8")
                return
            except Exception as err:
                last = str(err)
                time.sleep(0.05)
        raise RuntimeError(f"php -S startade inte: {last}")

    def close(self) -> None:
        if self.proc.poll() is None:
            self.proc.terminate()
            try:
                self.proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                self.proc.kill()
        self.hits.unlink(missing_ok=True)

    def hit_count(self) -> int:
        try:
            return int(self.hits.read_text(encoding="utf-8").strip() or "0")
        except OSError:
            return -1

    @property
    def gate(self) -> str:
        return f"http://127.0.0.1:{self.port}/index.php"


def write_router(path: Path) -> None:
    path.write_text(
        textwrap.dedent(
            """\
            <?php
            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if ($uri === '/hook.php') {
                $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
                $raw = file_get_contents('php://input');
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $ua === 'curl/8.5' && $raw === '{}') {
                    http_response_code(401);
                    header('Content-Type: application/json');
                    echo '{"ok":false}';
                    return true;
                }
                http_response_code(400);
                echo '{"ok":false}';
                return true;
            }
            if ($uri === '/index.php' || $uri === '/') {
                $hits = getenv('JEAB_GATE_HITS') ?: '';
                if ($hits !== '') {
                    $n = is_file($hits) ? (int) file_get_contents($hits) : 0;
                    file_put_contents($hits, (string) ($n + 1));
                }
                $mode = getenv('JEAB_GATE_MODE') ?: 'ok';
                if ($mode === 'waf') {
                    http_response_code(403);
                    echo '<title>Blocked Page</title>';
                    return true;
                }
                if ($mode === 'crash') {
                    http_response_code(500);
                    echo 'broken';
                    return true;
                }
                if ($mode === 'notext') {
                    http_response_code(200);
                    echo 'hej';
                    return true;
                }
                http_response_code(200);
                header('Content-Type: text/html; charset=utf-8');
                echo '<label>Ange kampanjkod</label>';
                return true;
            }
            return false;
            """
        ),
        encoding="utf-8",
    )


def seed_live(home: Path) -> None:
    (home / "src").mkdir(parents=True, exist_ok=True)
    (home / "src" / "ordrar.php").write_bytes(OLD)
    (home / "gone.txt").write_bytes(GONE)


TRASH_DAY = "2026-10-30"


def trash_root(home: Path) -> Path:
    return home / "data" / ".private" / "trash" / TRASH_DAY


def _under_trash(home: Path, path: Path) -> bool:
    try:
        path.resolve().relative_to(trash_root(home).resolve())
    except ValueError:
        return False
    return True


def remnants(home: Path) -> list[str]:
    found = []
    for dirpath, _dirs, files in os.walk(home):
        for name in files:
            path = Path(dirpath) / name
            if _under_trash(home, path):
                continue
            if name.endswith(".bak") or name.endswith(".tmp") or ".deploy-" in name or name.endswith(".uploading"):
                found.append(str(path))
    return found


def assert_data_is_only_trash(home: Path, label: str) -> None:
    data = home / "data"
    if not data.exists():
        return
    root = (home / "data" / ".private" / "trash").resolve()
    for dirpath, _dirs, files in os.walk(data):
        for name in files:
            path = (Path(dirpath) / name).resolve()
            try:
                path.relative_to(root)
            except ValueError:
                raise AssertionError(f"{label}: fil utanför papperskorgen {path}") from None


def deploy(
    repo: Path,
    home: Path,
    port: int,
    gate: str,
    extra: list[str],
    extra_env: dict[str, str] | None = None,
) -> subprocess.CompletedProcess[str]:
    env = os.environ.copy()
    env.pop("GITHUB_ACTIONS", None)
    env.update(
        {
            "LOOPIA_FTP_HOST": "127.0.0.1",
            "LOOPIA_FTP_USER": USER,
            "LOOPIA_FTP_PASS": PASSWORD,
            "LOOPIA_FTP_ROOT": "/",
            "LOOPIA_FTP_PORT": str(port),
            "JEAB_DEPLOY_GATE_URL": gate,
            "JEAB_DEPLOY_ATTEMPTS": "3",
            "JEAB_DEPLOY_RETRY_DELAYS": "0,0,0",
            "JEAB_DEPLOY_TIMEOUT": "8",
            "JEAB_DEPLOY_HTTP_TIMEOUT": "5",
            "JEAB_DEPLOY_BLOCKSIZE": "32",
            "JEAB_DEPLOY_TRASH_DAY": TRASH_DAY,
            "PYTHONUNBUFFERED": "1",
        }
    )
    if extra_env:
        env.update(extra_env)
    return subprocess.run(
        [sys.executable, str(SCRIPT), "--repo", str(repo), "--commit", "HEAD", "--insecure", "--allow-any-commit", *extra],
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def assert_secrets_hidden(proc: subprocess.CompletedProcess[str], label: str) -> None:
    blob = proc.stdout + proc.stderr
    if PASSWORD in blob or USER in blob:
        raise AssertionError(f"{label}: hemlighet syntes i skriptloggen")


def assert_no_live_stor(handler, label: str) -> None:
    bad = [name for name in handler.stor_log if ".tmp" not in name and name != "deploying.flag"]
    if bad:
        raise AssertionError(f"{label}: STOR träffade live-namn {bad}")


def test_units() -> None:
    module = load_module()
    module.reset_secrets()
    module.remember_secret(PASSWORD)
    if module.redact(f"lösen={PASSWORD}") != "lösen=***":
        raise AssertionError("redact dolde inte lösenordet")
    if module.deny_reason("tests/a.php") != "tests":
        raise AssertionError("tests/ ska nekas")
    if module.deny_reason("data/passwords.json") is None:
        raise AssertionError("data/ ska nekas")
    allow = "data/.private/tinyfm_allowlist.txt"
    if module.deny_reason(allow) is None or module.deny_reason("./" + allow) is None:
        raise AssertionError(f"{allow} ska nekas så deploy inte skriver över serverns lista")
    if module.deny_reason("src/ordrar.php") is not None:
        raise AssertionError("src/ordrar.php ska få deployas")
    if module.deny_reason("src/solkampanj/brochure.php") is not None:
        raise AssertionError("solbroschyren under src/ ska få deployas")
    if module.deny_reason("src/solkampanj/jarfalla-energi-solceller.pdf") is not None:
        raise AssertionError("platshållaren under src/ ska få skrivas över")
    if module.deny_reason("data/.private/solkampanj/jarfalla-energi-solceller.pdf") is None:
        raise AssertionError("data/.private ska fortfarande nekas")
    if module.deny_reason("fullmakt/sign.php") is not None or module.deny_reason("fullmakt/pdf.php") is not None:
        raise AssertionError("fullmaktens ingångar ska få deployas")
    for allowed in (
        "fullmakt/lib/bootstrap.php",
        "fullmakt/lib/mailer.php",
        "fullmakt/lib/pdf.php",
        "fullmakt/lib/store.php",
        "fullmakt/vendor/fpdf/fpdf.php",
        "fullmakt/vendor/fpdi/autoload.php",
        "fullmakt/vendor/.user.ini",
        "fullmakt/vendor/deny-direct.php",
    ):
        if module.deny_reason(allowed) is not None:
            raise AssertionError(f"{allowed} ska få deployas, fick {module.deny_reason(allowed)}")
    for blocked in (
        "fullmakt/data",
        "fullmakt/data/secret.php",
        "fullmakt/data/pending/not-a-real-token.json",
        "fullmakt/data/signed/not-a-real-token.pdf",
        ".user.ini",
        "fullmakt/.user.ini",
    ):
        if module.deny_reason(blocked) is None:
            raise AssertionError(f"{blocked} ska nekas")
    if module.deny_reason("index.php") is not None:
        raise AssertionError("index.php ska få deployas")
    if module.deny_reason("gi/data/loans.json") is None:
        raise AssertionError("gi/data ska nekas")
    if module.deny_reason("gi/photo.php") is not None or module.deny_reason("gi/lib.php") is not None or module.deny_reason("gi/style.css") is not None:
        raise AssertionError("gi-sidan och photo.php ska få deployas")
    for blocked in (
        "data/.private/tinyfm_allowlist.txt.example",
        "data/.private/passwords.json",
        "data/store.json",
        "data/tinyfm_allowlist.txt",
        "data/.private/nested/tinyfm_allowlist.txt",
    ):
        if module.deny_reason(blocked) is None:
            raise AssertionError(f"{blocked} ska nekas")
    if module.deny_reason("schema/mysql.php") is None:
        raise AssertionError("schema/ ska nekas")
    if module.deny_reason(".github/workflows/deploy-loopia.yml") is None:
        raise AssertionError(".github/ ska nekas")
    if module.deny_reason("README.md") is None or module.deny_reason("docs/README.txt") is None:
        raise AssertionError("README* ska nekas")
    if module.deny_reason("notes.md") is None:
        raise AssertionError("*.md ska nekas")
    if module.deny_reason("src/ordrar.php") is not None:
        raise AssertionError("src/ordrar.php ska få deployas")
    critical = module.boot_critical_paths(str(REPO))
    for required in (
        "src/ordrar.php",
        "src/bootstrap.php",
        "src/shield.php",
        "src/auth.php",
        "src/inkorg.php",
        "src/inkorg_contacts.php",
    ):
        if required not in critical:
            raise AssertionError(f"{required} ska vara boot-kritisk, fick {sorted(critical)}")
    if "src/test_lead.php" not in critical:
        raise AssertionError("src/test_lead.php ska vara boot-kritisk när bootstrap require:ar den")
    probe = "src/deploy_boot_probe.php"
    if probe in critical:
        raise AssertionError(f"{probe} ska inte ligga i den riktiga boot-listan")
    with tempfile.TemporaryDirectory() as probe_dir:
        probe_root = Path(probe_dir)
        (probe_root / "src").mkdir()
        boot_text = (REPO / "src" / "bootstrap.php").read_text(encoding="utf-8")
        needle = "function jeab_bootstrap_core_files(): array\n{\n    return [\n"
        if needle not in boot_text:
            raise AssertionError("kunde inte hitta core-arrayen i bootstrap.php")
        patched = boot_text.replace(needle, needle + "        'deploy_boot_probe.php',\n", 1)
        (probe_root / "src" / "bootstrap.php").write_text(patched, encoding="utf-8")
        probed = module.boot_critical_paths(str(probe_root))
        if probe not in probed:
            raise AssertionError(f"{probe} i core-arrayen ska vara boot-kritisk, fick {sorted(probed)}")
    batch = [
        "src/bootstrap.php",
        "src/inkorg.php",
        "src/inkorg_auto.php",
        "src/inkorg_follow.php",
        "src/inkorg_graph.php",
        "src/inkorg_no_email.php",
        "src/omtago.php",
        "src/send_guard_brake.php",
        "src/sms.php",
        "src/test_lead.php",
    ]
    ordered = module.order_deploy_paths(str(REPO), batch)
    if ordered.index("src/test_lead.php") > ordered.index("src/bootstrap.php"):
        raise AssertionError(f"test_lead.php ska bytas före bootstrap.php, fick {ordered}")
    if ordered.index("src/sms.php") > ordered.index("src/bootstrap.php"):
        raise AssertionError(f"sms.php ska bytas före bootstrap.php, fick {ordered}")
    if ordered != module.order_deploy_paths(str(REPO), list(reversed(batch))):
        raise AssertionError("deployordning ska inte bero på listans ursprungsordning")
    mixed = [
        "index.php",
        "hook.php",
        "webshop-hook.php",
        "graph-hook.php",
        "api/omtago-lead.php",
        "kalkyl.php",
        "src/bootstrap.php",
        "src/test_lead.php",
        "notes.txt",
    ]
    mixed_order = module.order_deploy_paths(str(REPO), mixed)
    src_at = max(i for i, path in enumerate(mixed_order) if path.startswith("src/"))
    entry_at = min(
        mixed_order.index(path)
        for path in ("index.php", "hook.php", "webshop-hook.php", "graph-hook.php", "api/omtago-lead.php", "kalkyl.php")
    )
    if src_at >= entry_at or mixed_order.index("src/test_lead.php") > mixed_order.index("src/bootstrap.php"):
        raise AssertionError(f"src ska före ingångar: {mixed_order}")
    if mixed_order.index("notes.txt") < src_at or mixed_order.index("notes.txt") > entry_at:
        raise AssertionError(f"övriga filer ska ligga mellan src och ingångar: {mixed_order}")
    layered = [
        "assets/js/inkorg-nav.js",
        "src/pages/inkorg.php",
        "index.php",
        "notes.txt",
        "src/bootstrap.php",
        "src/test_lead.php",
    ]
    layered_order = module.order_deploy_paths(str(REPO), layered)

    def at(path: str) -> int:
        return layered_order.index(path)

    if not (
        at("src/test_lead.php")
        < at("src/bootstrap.php")
        < at("src/pages/inkorg.php")
        < at("notes.txt")
        < at("index.php")
        < at("assets/js/inkorg-nav.js")
    ):
        raise AssertionError(
            f"ordning ska vara src, src/pages, övrigt, ingångar, assets sist: {layered_order}"
        )
    if module.is_runtime_path("tests/hidden.php") or module.is_runtime_path(".github/workflows/deploy-loopia.yml"):
        raise AssertionError("tester och .github är inte körfiler")
    if module.is_runtime_path("notes.md") or module.is_runtime_path("README.md"):
        raise AssertionError("spärrade filer är inte körfiler")
    for runtime in ("src/ordrar.php", "src/pages/inkorg.php", "assets/js/inkorg-nav.js", "index.php"):
        if not module.is_runtime_path(runtime):
            raise AssertionError(f"{runtime} ska räknas som körfil")
    body = module.blob(str(REPO), "HEAD", "index.php")

    class MemOps:
        def __init__(self, files: dict[str, bytes]) -> None:
            self.files = files

        def read(self, rel_dir: str, name: str) -> bytes:
            key = name if not rel_dir or rel_dir == "." else f"{rel_dir}/{name}"
            if key not in self.files:
                raise module.MissingFile(key)
            return self.files[key]

    live_cfg = type("Cfg", (), {"repo": str(REPO), "commit": "HEAD", "ops": MemOps({"index.php": body})})()
    module.verify_live_against_commit(live_cfg, [module.Item("deploy", "index.php"), module.Item("keep-deleted", "gone.txt")])
    live_cfg.ops = MemOps({"index.php": body + b"x"})
    try:
        module.verify_live_against_commit(live_cfg, [module.Item("deploy", "index.php")])
    except module.DeployError as err:
        if "index.php" not in str(err):
            raise AssertionError(f"mismatch ska nämna sökvägen, fick {err}") from err
    else:
        raise AssertionError("live sha som skiljer sig från committen ska stoppa")
    live_cfg.ops = MemOps({})
    try:
        module.verify_live_against_commit(live_cfg, [module.Item("deploy", "index.php")])
    except module.DeployError as err:
        if "saknas" not in str(err):
            raise AssertionError(f"saknad live-fil ska stoppa, fick {err}") from err
    else:
        raise AssertionError("saknad live-fil ska stoppa")
    if module.is_deploy_entry("src/kalkyl.php") or not module.is_deploy_entry("kalkyl-hook.php"):
        raise AssertionError("kalkyl-ingångar utanför src/ ska vara sist, src/kalkyl.php är src")
    if module.HOOK_UA != "curl/8.5":
        raise AssertionError("hook-kontrollen ska använda curl/8.5")
    if not module.classify_hook_response(401, b'{"ok":false,"error":"Fel token."}'):
        raise AssertionError("401 och ok false ska vara en frisk hook")
    for bad_status, bad_body in (
        (200, b'{"ok":false}'),
        (401, b'{"ok":true}'),
        (401, b"inte json"),
        (503, b'{"ok":false}'),
    ):
        if module.classify_hook_response(bad_status, bad_body):
            raise AssertionError(f"hook {bad_status} {bad_body!r} skulle ha nekats")
    for blocked in (
        "rot/fortnox_tokens.json",
        "rot/tokens.json",
        "data/.private/fortnox/tokens.json",
        "data/.private/rot/fortnox_tokens.json",
        "Fortnox_Tokens.JSON",
    ):
        if module.deny_reason(blocked) is None:
            raise AssertionError(f"{blocked} ska nekas")
    if module.deny_reason("deploy-check.txt") is not None:
        raise AssertionError("deploy-check.txt ska få deployas")
    if module.is_smoke_name("deploy-check.txt") and module.is_smoke_name("smoke.txt") and module.is_smoke_name("Smoke.PHP"):
        pass
    else:
        raise AssertionError("smoke-namn ska kännas igen")
    if module.is_smoke_name("ordrar.php") or module.is_smoke_name("smokey.php"):
        raise AssertionError("vanliga filer ska inte räknas som smoke")
    junk_samples = (
        "layout.php.bak",
        ".htaccess.bak",
        ".user.ini.bak1",
        ".user.ini.bak-old",
        ".layout.php.uploading",
        "file.uploading",
        "ordrar.php.deploy-abc123.tmp",
    )
    for sample in junk_samples:
        if not module.is_transfer_junk(sample):
            raise AssertionError(f"{sample} ska räknas som skräp")
    for sample in ("ordrar.php", "backup.php", "keep.php", "gone.txt", ".htaccess", "deploy-check.txt"):
        if module.is_transfer_junk(sample):
            raise AssertionError(f"{sample} ska inte räknas som överföringsskräp")
    bak_names = module.stale_bak_names(
        "bookings.php",
        [
            "bookings.php",
            "bookings.php.bak",
            "bookings.php.bak1",
            "bookings.php.bak-old",
            "bookings.php.bak.old",
            "bookings.php.backup",
            "bookings.php.deploy-abc.tmp",
            "index.php.bak",
            ".htaccess.bak",
            ".user.ini.bak1",
        ],
    )
    if bak_names != [
        "bookings.php.bak",
        "bookings.php.bak1",
        "bookings.php.bak-old",
        "bookings.php.bak.old",
    ]:
        raise AssertionError(f"fel .bak-urval: {bak_names}")
    if module.stale_bak_names(".user.ini", [".user.ini", ".user.ini.bak1", ".user.ini.bak-old", "index.php.bak"]) != [
        ".user.ini.bak1",
        ".user.ini.bak-old",
    ]:
        raise AssertionError("dotfilens .bak* ska höras till live-namnet")
    if module.stale_bak_names("ordrar.php", ["ordrar.php"]) != []:
        raise AssertionError("live-filen får inte räknas som backup")
    if module.live_stem_for_cleanup("src/bookings.php") != "bookings.php":
        raise AssertionError("live-sökväg ska ge live-namnet")
    if module.live_stem_for_cleanup("src/bookings.php.bak") != "bookings.php":
        raise AssertionError(".bak-sökväg ska ge live-namnet")
    if module.live_stem_for_cleanup(".htaccess.bak1") != ".htaccess":
        raise AssertionError(".htaccess.bak1 ska ge .htaccess")
    try:
        module.live_stem_for_cleanup(".bak")
    except module.DeployError:
        pass
    else:
        raise AssertionError("tomt backupnamn skulle ha stoppats")
    import tinyfm_client as tinyfm_client_mod

    listed = tinyfm_client_mod.parse_listed_names(
        '<input type="checkbox" name="file[]" value="ordrar.php">'
        '<input type="checkbox" name="file[]" value=".layout.php.uploading">'
        '<input type="hidden" name="file[]" value="secret.bak">'
        '<input type="checkbox" id="js-select-all-items">'
        '<input type="checkbox" name="file[]" value="ordrar.php">'
    )
    if listed != ["ordrar.php", ".layout.php.uploading"]:
        raise AssertionError(f"listtolkning fel: {listed}")
    if module.deny_reason(".htaccess") is not None:
        raise AssertionError(".htaccess ska få deployas")
    if module.deny_reason("private/.htaccess") is not None:
        raise AssertionError("private/.htaccess ska få deployas")
    if module.deny_reason("private/backups/.htaccess") is not None:
        raise AssertionError("private/backups/.htaccess ska få deployas")
    if module.deny_reason("private/deploying.flag") is None:
        raise AssertionError("private/deploying.flag ska inte deployas som vanlig fil")
    if module.deny_reason("private/backups/secret.json") is None:
        raise AssertionError("filer under private/ ska nekas")
    private_ht = (REPO / "private" / ".htaccess").read_bytes()
    backups_ht = (REPO / "private" / "backups" / ".htaccess").read_bytes()
    if private_ht != backups_ht:
        raise AssertionError("private/backups/.htaccess ska vara samma spärr")
    if not module.htaccess_blocks_private(private_ht):
        raise AssertionError("private/.htaccess ska neka med Require, Order, Deny och -Indexes")
    if module.htaccess_blocks_private(None) or module.htaccess_blocks_private(b"Require all denied\n"):
        raise AssertionError("saknad fil eller bara ena syntaxen är inte en spärr")
    if module.htaccess_blocks_private(b"Deny from all\n") :
        raise AssertionError("bara Deny from all är inte en spärr")
    if module.htaccess_blocks_private(b"Require all denied\nDeny from all\n"):
        raise AssertionError("Require och Deny utan Order och -Indexes är inte en spärr")
    weak_rewrite = (
        b"Options -Indexes\nRequire all denied\nOrder deny,allow\nDeny from all\nRewriteEngine On\n"
    )
    if module.htaccess_blocks_private(weak_rewrite):
        raise AssertionError("RewriteEngine On utan [F] är inte en spärr")
    full_block = (
        b"Options -Indexes\nRequire all denied\nOrder deny,allow\nDeny from all\n"
        b"RewriteEngine On\nRewriteRule ^ - [F,L]\n"
    )
    sample = [module.Item("deploy", "src/ordrar.php")]
    if module.prepend_private_htaccess(sample, full_block, True) != sample:
        raise AssertionError("en fil som redan spärrar ska inte läggas till")
    added = module.prepend_private_htaccess(sample, None, True)
    if [item.path for item in added] != ["private/.htaccess", "src/ordrar.php"]:
        raise AssertionError(f"saknad spärr ska ligga först, fick {[item.path for item in added]}")
    if module.prepend_private_htaccess(sample, None, False) != sample:
        raise AssertionError("utan fil i committen ska listan vara oförändrad")
    if module.prepend_private_htaccess(added, None, True) == added and added[0].path == "private/.htaccess":
        pass
    else:
        raise AssertionError("spärrfilen ska inte dubbleras")
    if module.deny_reason(".github/workflows/deploy-loopia.yml") != ".github":
        raise AssertionError(".github/ ska nekas med rätt orsak")
    if module.deny_reason(".github/deploy-tests/loopia_deploy_test.py") != ".github":
        raise AssertionError(".github/deploy-tests/ ska nekas")
    parsed = module.parse_name_status_z(b"M\0src/ordrar.php\0D\0gone.txt\0R100\0old.php\0new.php\0")
    if parsed != [("M", "src/ordrar.php", None), ("D", "gone.txt", None), ("R", "new.php", "old.php")]:
        raise AssertionError(f"diff-tolkning fel: {parsed}")
    needle = "Ange kampanjkod"
    cases = (
        (200, b"<label>Ange kampanjkod</label>", "green"),
        (403, b"<title>Blocked Page</title>", "unclear"),
        (403, b"forbidden", "unclear"),
        (500, b"broken", "rollback"),
        (200, b"hej", "rollback"),
        (404, b"saknas", "unclear"),
    )
    day = "2026-10-30"
    if module.trash_rel("src/ordrar.php.bak", day) != "data/.private/trash/2026-10-30/src/ordrar.php.bak":
        raise AssertionError(f"trash_rel src: {module.trash_rel('src/ordrar.php.bak', day)}")
    if module.trash_rel(".htaccess.bak", day) != "data/.private/trash/2026-10-30/.htaccess.bak":
        raise AssertionError(f"trash_rel rot: {module.trash_rel('.htaccess.bak', day)}")
    if module.trash_rel("./src/ordrar.php.bak1", day) != "data/.private/trash/2026-10-30/src/ordrar.php.bak1":
        raise AssertionError("trash_rel normaliserade inte ./ och .bak1")
    if module.trash_rel(".user.ini.bak1", day) != "data/.private/trash/2026-10-30/.user.ini.bak1":
        raise AssertionError("trash_rel missade .user.ini.bak1")
    for bad in ("../x.bak", "/etc/passwd.bak", "src/../../x.bak", "", "data/.private/trash/2026-10-30/src/x.bak", "data/.private/trash/x.bak"):
        try:
            module.trash_rel(bad, day)
        except module.DeployError:
            pass
        else:
            raise AssertionError(f"trash_rel släppte igenom {bad!r}")
    try:
        module.trash_rel("src/a.bak", "13 Nov")
    except module.DeployError:
        pass
    else:
        raise AssertionError("trash_rel släppte igenom ett datum som inte är YYYY-MM-DD")
    previous_day = os.environ.get("JEAB_DEPLOY_TRASH_DAY")
    os.environ["JEAB_DEPLOY_TRASH_DAY"] = day
    try:
        if module.trash_day() != day or module.trash_rel("src/a.bak") != f"data/.private/trash/{day}/src/a.bak":
            raise AssertionError("JEAB_DEPLOY_TRASH_DAY styr inte sökvägen")
    finally:
        if previous_day is None:
            os.environ.pop("JEAB_DEPLOY_TRASH_DAY", None)
        else:
            os.environ["JEAB_DEPLOY_TRASH_DAY"] = previous_day
    for status, body, expect in cases:
        got = module.classify_gate_response(status, body, needle)
        if got != expect:
            raise AssertionError(f"grind {status} {body!r} blev {got}, väntade {expect}")
    print("PASS enheter: spärrlista, redact, diff och grindutfall")


def test_mask_cookie_and_session() -> None:
    module = load_module()
    module.reset_secrets()
    session_id = "php-session-9f3a7c1e"
    token = "csrf-token-aabbccddeeff0011"
    previous = os.environ.get("GITHUB_ACTIONS")
    os.environ["GITHUB_ACTIONS"] = "true"
    stdout = sys.stdout
    try:
        sys.stdout = captured_stdout = __import__("io").StringIO()
        module.mask_value(session_id)
        module.mask_value("rad\nbrytning-hemlig")
        module.mask_value("kort")
        module.log(f"cookie={session_id}")
        client_spec = importlib.util.spec_from_file_location(
            "tinyfm_client_mask", REPO / ".github" / "scripts" / "tinyfm_client.py"
        )
        if client_spec is None or client_spec.loader is None:
            raise AssertionError("kan inte ladda tinyfm_client")
        client_mod = importlib.util.module_from_spec(client_spec)
        client_spec.loader.exec_module(client_mod)

        class Headers:
            def get_all(self, name):
                return [f"PHPSESSID={session_id}; path=/", "lang=sv"]

        client = client_mod.TinyFmClient(
            "http://127.0.0.1/filer.php", "u", "p", "c", gap=0, mask=module.mask_value
        )
        client._mask_inputs({"csrf": "kort", "token": token, "fm_usr": "einstein"})
        client._mask_set_cookie(Headers())
        module.log(f"token={token}")
    finally:
        sys.stdout = stdout
        if previous is None:
            os.environ.pop("GITHUB_ACTIONS", None)
        else:
            os.environ["GITHUB_ACTIONS"] = previous
        module.reset_secrets()
    text = captured_stdout.getvalue()
    if text.count(f"::add-mask::{session_id}") < 1 or f"::add-mask::{token}" not in text:
        raise AssertionError("sessions-id eller token maskerades inte")
    if "rad\nbrytning" in text or "::add-mask::kort" in text or "::add-mask::sv" in text or "einstein" in text:
        raise AssertionError("kort värde, radbrytning eller användarnamn ska inte skrivas ut")
    if f"cookie={session_id}" in text or f"token={token}" in text:
        raise AssertionError("loggen visade cookie eller token")
    print("PASS mask: cookie och sessions-id, inget echo av secrets")


def test_workflow_is_manual() -> None:
    text = WORKFLOW.read_text(encoding="utf-8")
    if "workflow_dispatch:" not in text:
        raise AssertionError("workflow_dispatch saknas")
    for banned in ("\n  pull_request:", "\n  pull_request_target:", "\n  schedule:"):
        if banned in text:
            raise AssertionError(f"workflow får inte ha trigger {banned.strip()}")
    for line in text.splitlines():
        stripped = line.strip()
        if stripped.startswith("#"):
            continue
        if stripped.startswith(("pull_request", "schedule:")):
            raise AssertionError(f"workflow får inte ha trigger {stripped}")
    if "\n  push:" not in text or "vars.AUTO_DEPLOY == 'true'" not in text or "--from-diff" not in text:
        raise AssertionError("push ska deploya diffen bara när AUTO_DEPLOY är true")
    push_block = text.split("\n  push:", 1)[1].split("\nconcurrency:", 1)[0]
    if "- main" not in push_block:
        raise AssertionError("push ska bara gälla main")
    if "github.event.before" not in text:
        raise AssertionError("push-diff ska använda before-committen")
    if "LOOPIA_FTP_PASS" not in text or "secrets.LOOPIA_FTP_PASS" not in text:
        raise AssertionError("lösenordet ska läsas från secrets")
    if "set -x" in text or "printenv" in text:
        raise AssertionError("workflow får inte skriva ut miljön")
    if "environment:" in text:
        raise AssertionError("workflow ska inte använda GitHub Environment")
    if "github.ref == 'refs/heads/main'" not in text:
        raise AssertionError("deploy ska bara köra från refs/heads/main")
    if 'default: "tinyfm"' not in text or "default_transport=tinyfm" not in text:
        raise AssertionError("saknade FTP-secrets ska ge transport tinyfm")
    if "github.event_name == 'workflow_dispatch'" not in text or "github.event_name == 'push'" not in text:
        raise AssertionError("deploy ska ta workflow_dispatch och push")
    if "contents: read" not in text:
        raise AssertionError("permissions ska vara contents: read")
    self_test, deploy_job = text.split("\n  deploy:", 1)
    if "secrets." in self_test.split("jobs:", 1)[-1]:
        raise AssertionError("self-test får inte läsa secrets")
    if "environment:" in deploy_job:
        raise AssertionError("deploy-jobbet ska inte ha environment")
    uses = []
    for line in text.splitlines():
        stripped = line.strip()
        if stripped.startswith("uses:"):
            uses.append(stripped.split("uses:", 1)[1].strip().split()[0])
    if not uses:
        raise AssertionError("checkout saknas")
    for use in uses:
        name, _, pin = use.partition("@")
        if name != "actions/checkout" or len(pin) != 40 or any(char not in "0123456789abcdef" for char in pin):
            raise AssertionError(f"action ska vara actions/checkout på full SHA, fick {use}")
    for needle in (
        "- deploy\n",
        "- check\n",
        "- rollback\n",
        "- cleanup-bak\n",
        "- auto\n",
        "- ftp\n",
        "- tinyfm\n",
        "src/sms.php,index.php,src/ordrar.php",
        "--mode",
        "--transport",
        "TINYFM_USER",
        "TINYFM_PASS",
        "JEAB_GATE_CODE",
        "secrets.LOOPIA_FTP_HOST",
        "secrets.LOOPIA_FTP_USER",
        "secrets.LOOPIA_FTP_PASS",
        "secrets.TINYFM_USER",
        "secrets.TINYFM_PASS",
        "secrets.JEAB_GATE_CODE",
        "secrets.JEAB_GATE_ID",
        "python3 .github/deploy-tests/loopia_deploy_test.py",
        "- service-redirect\n",
        "since_sha:",
        "--since-sha",
        "TORRKÖRNING – INGET UPPLADDAT",
    ):
        if needle not in text:
            raise AssertionError(f"workflow saknar {needle!r}")
    if "python3 tests/loopia_deploy_test.py" in text:
        raise AssertionError("self-test ska inte ligga i tests/")
    if "keep_smoke:" not in text or "--keep-smoke" not in text or "default: false" not in text:
        raise AssertionError("workflow ska städa smoke som standard och bara behålla dem med keep_smoke")
    if "maintenance:" not in text or "--maintenance" not in text:
        raise AssertionError("workflow ska kunna visa underhåll, av som standard")
    for banned in ("JEAB_CAMPAIGN_CODE", "JEAB_PASSWORD", "TINYFM_PASSWORD", "JEAB_CURRENT_CAMPAIGN_CODE"):
        if banned in text:
            raise AssertionError(f"workflow ska inte använda aliaset {banned}")
    print("PASS workflow: dispatch kvar, push-diff bara med AUTO_DEPLOY")


def test_dry_run(repo: Path) -> None:
    summary = repo.parent / "step-summary.md"
    summary.write_text("", encoding="utf-8")
    proc = deploy(
        repo,
        repo,
        1,
        "http://127.0.0.1:9/index.php",
        ["--dry-run"],
        extra_env={"GITHUB_STEP_SUMMARY": str(summary)},
    )
    assert_secrets_hidden(proc, "torrkörning")
    text = proc.stdout
    if proc.returncode != 0:
        raise AssertionError(f"torrkörning exit {proc.returncode}\n{text}\n{proc.stderr}")
    for needle in (
        "SKULLE deploya src/ordrar.php",
        "SKULLE deploya deploy-check.txt",
        "hoppar        README.md",
        "hoppar        tests/hidden.php",
        "hoppar        data/passwords.json",
        "hoppar        schema/mysql.php",
        "hoppar        .github/workflows/nope.yml",
        "hoppar        notes.md",
        "raderas inte  gone.txt",
        "FTP kontaktas inte",
        "::error::TORRKÖRNING – INGET UPPLADDAT",
    ):
        if needle not in text:
            raise AssertionError(f"torrkörning saknar {needle}\n{text}")
    summary_text = summary.read_text(encoding="utf-8")
    if "::error::TORRKÖRNING – INGET UPPLADDAT" not in summary_text:
        raise AssertionError(f"sammanfattningen saknar torrkörningsfelet\n{summary_text}")
    check = deploy(
        repo,
        repo,
        1,
        "http://127.0.0.1:9/index.php",
        ["--dry-run", "--mode", "check", "--paths", "deploy-check.txt"],
    )
    if check.returncode != 0 or "::error::TORRKÖRNING" in check.stdout:
        raise AssertionError(f"check-torrkörning ska inte säga att deploy uteblev\n{check.stdout}\n{check.stderr}")
    warned = deploy(
        repo,
        repo,
        1,
        "http://127.0.0.1:9/index.php",
        ["--dry-run", "--paths", "deploy-check.txt", "--since-sha", "HEAD~1"],
        extra_env={"GITHUB_STEP_SUMMARY": str(summary)},
    )
    warn_text = warned.stdout + warned.stderr
    if warned.returncode != 0:
        raise AssertionError(f"since_sha-varning ska inte fälla jobbet\n{warn_text}")
    warn_lines = [line for line in warn_text.splitlines() if line.startswith("::warning::")]
    if len(warn_lines) != 1 or "src/ordrar.php" not in warn_lines[0] or "saknas i paths" not in warn_lines[0]:
        raise AssertionError(f"since_sha ska varna om src/ordrar.php\n{warn_text}")
    for absent in ("tests/hidden.php", "notes.md", "README.md", "data/passwords.json", ".github/workflows/nope.yml"):
        if absent in warn_lines[0]:
            raise AssertionError(f"since_sha ska inte varna om {absent}\n{warn_lines[0]}")
    if "::warning::" not in summary.read_text(encoding="utf-8"):
        raise AssertionError("since_sha-varningen ska ligga i sammanfattningen")
    print("PASS torrkörning: listar deploy, hoppar över spärrat, raderar inte gone.txt")


def test_from_diff_zero(repo: Path) -> None:
    proc = deploy(
        repo,
        repo,
        1,
        "http://127.0.0.1:9/index.php",
        ["--from-diff", "--base", "0" * 40],
    )
    assert_secrets_hidden(proc, "noll-before")
    if proc.returncode == 0 or "Ingen live-fil rördes" not in proc.stdout:
        raise AssertionError(f"noll-before skulle ha stoppat\n{proc.stdout}\n{proc.stderr}")
    print("PASS push utan before rör ingen live-fil")


def test_explicit_deny(repo: Path) -> None:
    proc = deploy(repo, repo, 1, "http://127.0.0.1:9/index.php", ["--dry-run", "--paths", "data/passwords.json"])
    assert_secrets_hidden(proc, "nekad sökväg")
    if proc.returncode == 0 or "nekad" not in proc.stdout:
        raise AssertionError(f"utpekad hemlighet skulle ha nekats\n{proc.stdout}")
    allow = "data/.private/tinyfm_allowlist.txt"
    allowed = deploy(repo, repo, 1, "http://127.0.0.1:9/index.php", ["--dry-run", "--paths", allow])
    assert_secrets_hidden(allowed, "tinyfm-allowlist")
    if allowed.returncode == 0 or "nekad" not in allowed.stdout:
        raise AssertionError(f"{allow} skulle ha nekats\n{allowed.stdout}\n{allowed.stderr}")
    tokens = deploy(
        repo,
        repo,
        1,
        "http://127.0.0.1:9/index.php",
        ["--dry-run", "--paths", "rot/fortnox_tokens.json,data/.private/fortnox/tokens.json"],
    )
    assert_secrets_hidden(tokens, "fortnox-token")
    token_text = tokens.stdout + tokens.stderr
    if tokens.returncode == 0 or "nekad" not in token_text or "fortnox-token" not in token_text:
        raise AssertionError(f"fortnox-token skulle ha nekats\n{token_text}")
    sibling = deploy(
        repo,
        repo,
        1,
        "http://127.0.0.1:9/index.php",
        ["--dry-run", "--paths", "data/.private/passwords.json"],
    )
    assert_secrets_hidden(sibling, "data/.private hemlighet")
    if sibling.returncode == 0 or "nekad" not in sibling.stdout:
        raise AssertionError(f"övriga data/.private skulle ha nekats\n{sibling.stdout}")
    print("PASS utpekad hemlighet nekas, tinyfm-allowlist skrivs inte över")


def scenario(
    label: str,
    repo: Path,
    cert: Path,
    key: Path,
    router: Path,
    *,
    reject_overwrite: bool = False,
    abort_tmp: bool = False,
    corrupt_tmp: bool = False,
    gate_mode: str = "ok",
    extra: list[str],
    expect_exit: int,
    expect_live: bytes,
    expect_in_log: tuple[str, ...] = (),
    forbid_in_log: tuple[str, ...] = (),
    forbid_in_rename: tuple[str, ...] = (),
    require_bak_rename: bool = False,
    expect_bak: bool = False,
    expect_hits: int | None = None,
    expect_gate_calls: int | None = None,
) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-ftp-"))
    ftp = None
    php = None
    try:
        seed_live(home)
        before = (home / "src" / "ordrar.php").read_bytes()
        handler = make_handler(cert, key, home)
        handler.reject_overwrite = reject_overwrite
        handler.abort_tmp = abort_tmp
        handler.corrupt_tmp = corrupt_tmp
        ftp = FtpWorld(home, handler)
        php = PhpWorld(home, router, gate_mode)
        proc = deploy(repo, home, ftp.port, php.gate, extra)
        assert_secrets_hidden(proc, label)
        assert_no_live_stor(handler, label)
        text = proc.stdout + proc.stderr
        live = (home / "src" / "ordrar.php").read_bytes()
        if proc.returncode != expect_exit:
            raise AssertionError(f"{label}: exit {proc.returncode}, väntade {expect_exit}\n{text}")
        if live != expect_live:
            raise AssertionError(
                f"{label}: live är {len(live)} byte, väntade {len(expect_live)}. "
                f"Före körningen var den {len(before)} byte.\n{text}"
            )
        if before != OLD:
            raise AssertionError(f"{label}: fröet var inte OLD")
        leftover = remnants(home)
        if expect_bak:
            other = [path for path in leftover if not path.endswith(".bak")]
            if other or not any(path.endswith("ordrar.php.bak") for path in leftover):
                raise AssertionError(f"{label}: väntade kvarvarande .bak, fick {leftover}\n{text}")
        elif leftover:
            raise AssertionError(f"{label}: rester kvar {leftover}\n{text}")
        if expect_hits is not None and php.hit_count() != expect_hits:
            raise AssertionError(f"{label}: grindanrop {php.hit_count()}, väntade {expect_hits}\n{text}")
        if expect_gate_calls is not None and text.count("ett grindanrop") != expect_gate_calls:
            raise AssertionError(
                f"{label}: loggade grindanrop {text.count('ett grindanrop')}, väntade {expect_gate_calls}\n{text}"
            )
        if (home / "gone.txt").read_bytes() != GONE:
            raise AssertionError(f"{label}: gone.txt ändrades eller försvann")
        flag = home / "private" / "deploying.flag"
        if expect_exit == 0:
            if flag.is_file() and flag.read_bytes().strip() != b"":
                raise AssertionError(f"{label}: vanlig deploy ska inte lämna underhållsflaggan på")
            if handler.stor_log and handler.stor_log[0] == "deploying.flag":
                raise AssertionError(f"{label}: vanlig deploy ska inte skriva flaggan först, fick {handler.stor_log}")
        elif flag.exists() and flag.read_bytes().strip() != b"":
            raise AssertionError(f"{label}: flaggan lämnades på")
        if (home / "README.md").exists() or (home / "tests").exists():
            raise AssertionError(f"{label}: spärrad fil laddades upp")
        assert_data_is_only_trash(home, label)
        for needle in expect_in_log:
            if needle not in text:
                raise AssertionError(f"{label}: loggen saknar {needle!r}\n{text}")
        for banned in forbid_in_log:
            if banned in text:
                raise AssertionError(f"{label}: loggen innehåller {banned!r}\n{text}")
        joined = " ".join(src + "->" + dst for src, dst, _status in handler.rename_log)
        for banned in forbid_in_rename:
            if banned in joined:
                raise AssertionError(f"{label}: rename-loggen innehåller {banned}: {handler.rename_log}")
        if require_bak_rename and not any(dst.endswith(".bak") and status == "ok" for _src, dst, status in handler.rename_log):
            raise AssertionError(f"{label}: .bak användes inte: {handler.rename_log}")
        if label.startswith("avbrott") and live != before:
            raise AssertionError(f"{label}: live ändrades vid avbrott")
        proof = [
            line
            for line in text.splitlines()
            if any(
                token in line
                for token in (
                    "rördes inte",
                    "via ",
                    "återställning",
                    "raderade",
                    "rester från körningen",
                    "tmp stämmer inte",
                    "tmp verifierad",
                    "FEL:",
                    "Ange kampanjkod",
                    "OKLART",
                    "lämnas kvar",
                    "ett grindanrop",
                    "grind kräver rollback",
                )
            )
        ]
        print(
            f"PASS {label}: exit={proc.returncode} live={len(live)} byte "
            f"oförändrad={live == before} rester=0 STOR-anrop={len(handler.stor_log)}"
        )
        for line in proof:
            print(f"  | {line}")
        return text
    finally:
        if php is not None:
            php.close()
        if ftp is not None:
            ftp.close()
        shutil.rmtree(home, ignore_errors=True)


def test_abort(repo: Path, cert: Path, key: Path, router: Path) -> None:
    scenario(
        "avbrott mitt i uppladdningen",
        repo,
        cert,
        key,
        router,
        abort_tmp=True,
        extra=["--paths", "src/ordrar.php"],
        expect_exit=1,
        expect_live=OLD,
        expect_in_log=("Live-filen", "rördes inte"),
        forbid_in_log=("ett grindanrop", "återställning"),
        expect_hits=0,
        expect_gate_calls=0,
    )


def test_corrupt(repo: Path, cert: Path, key: Path, router: Path) -> None:
    scenario(
        "tmp verifieras och kastas",
        repo,
        cert,
        key,
        router,
        corrupt_tmp=True,
        extra=["--paths", "src/ordrar.php"],
        expect_exit=1,
        expect_live=OLD,
        expect_in_log=("tmp stämmer inte", "rördes inte"),
        forbid_in_log=("ett grindanrop",),
        expect_hits=0,
        expect_gate_calls=0,
    )


def test_from_diff(repo: Path, cert: Path, key: Path, router: Path) -> None:
    scenario(
        "push-diff deployar bara ändrade filer",
        repo,
        cert,
        key,
        router,
        extra=["--from-diff", "--base", "HEAD^"],
        expect_exit=0,
        expect_live=NEW,
        expect_in_log=(
            "hoppar        README.md",
            "raderas inte  gone.txt",
            "via bak",
            "via ny-fil",
            "ett grindanrop",
            "rester från körningen: inga",
        ),
        require_bak_rename=True,
        expect_hits=1,
        expect_gate_calls=1,
    )


def test_two_files(repo: Path, cert: Path, key: Path, router: Path) -> None:
    scenario(
        "två filer, ett grindanrop, bak raderas",
        repo,
        cert,
        key,
        router,
        extra=["--paths", "deploy-check.txt,src/ordrar.php"],
        expect_exit=0,
        expect_live=NEW,
        expect_in_log=(
            "via bak",
            "via ny-fil",
            "ett grindanrop",
            "Ange kampanjkod",
            "rester från körningen: inga",
        ),
        require_bak_rename=True,
        expect_hits=1,
        expect_gate_calls=1,
    )


def _assert_sha_before_stale_delete(text: str, label: str) -> None:
    verified = text.find("tmp verifierad")
    stale = text.find("finns redan")
    swapped = text.find("byte klart")
    if verified < 0 or stale < 0 or swapped < 0 or not (verified < stale < swapped):
        raise AssertionError(f"{label}: sha ska verifieras innan gammal .bak raderas\n{text}")
    if "Ta bort eller döp om backupen" in text:
        raise AssertionError(f"{label}: gammal vägran ligger kvar\n{text}")


def test_stale_bak_is_deleted_and_deploy_continues(repo: Path, cert: Path, key: Path, router: Path) -> None:
    """Kvarlämnad .bak och .bak* ska bort efter sha-kontroll, sedan vanligt byte."""
    home = Path(tempfile.mkdtemp(prefix="jeab-stale-bak-"))
    ftp = None
    php = None
    stale = b"STALE-BAK-NOT-LIVE\n" * 30
    try:
        seed_live(home)
        src = home / "src"
        (src / "ordrar.php.bak").write_bytes(stale)
        (src / "ordrar.php.bak1").write_bytes(b"STALE-BAK1")
        (src / "ordrar.php.bak-old").write_bytes(b"STALE-BAK-OLD")
        (src / "layout.php.bak").write_bytes(b"sweep-later")
        other = home / "other"
        other.mkdir()
        (other / "notes.php.bak").write_bytes(b"lamna-bak")
        handler = make_handler(cert, key, home)
        handler.reject_overwrite = True
        ftp = FtpWorld(home, handler)
        php = PhpWorld(home, router, "ok")
        proc = deploy(repo, home, ftp.port, php.gate, ["--paths", "src/ordrar.php"])
        assert_secrets_hidden(proc, "gammal bak")
        assert_no_live_stor(handler, "gammal bak")
        text = proc.stdout + proc.stderr
        live = (src / "ordrar.php").read_bytes()
        if proc.returncode != 0 or live != NEW:
            raise AssertionError(f"gammal bak stoppade deployen exit={proc.returncode}\n{text}")
        if stale in live or b"STALE-BAK1" in live:
            raise AssertionError("live blev den gamla backupen")
        _assert_sha_before_stale_delete(text, "gammal bak")
        for needle in (
            "src/ordrar.php.bak finns redan",
            "src/ordrar.php.bak1 finns redan",
            "src/ordrar.php.bak-old finns redan",
            "sha ok via bak",
            "ett grindanrop",
            "Ange kampanjkod",
            "rester från körningen: inga",
        ):
            if needle not in text:
                raise AssertionError(f"gammal bak saknar {needle!r}\n{text}")
        if php.hit_count() != 1 or text.count("ett grindanrop") != 1:
            raise AssertionError(f"grinden anropades fel\n{text}")
        for gone in (
            src / "ordrar.php.bak",
            src / "ordrar.php.bak1",
            src / "ordrar.php.bak-old",
            src / "layout.php.bak",
        ):
            if gone.exists():
                raise AssertionError(f"{gone.name} ligger kvar efter grön grind")
        if (other / "notes.php.bak").read_bytes() != b"lamna-bak":
            raise AssertionError("annans .bak i orörd mapp togs bort")
        if not any(os.path.basename(dst).endswith(".bak") and status == "ok" for _src, dst, status in handler.rename_log):
            raise AssertionError(f".bak-bytet kördes inte: {handler.rename_log}")
        parked = trash_root(home) / "src"
        if (parked / "ordrar.php.bak").read_bytes() != stale:
            raise AssertionError("den gamla ordrar.php.bak raderades i stället för att flyttas")
        if (parked / "ordrar.php.bak1").read_bytes() != b"STALE-BAK1":
            raise AssertionError("ordrar.php.bak1 saknas i papperskorgen")
        if (parked / "ordrar.php.bak-old").read_bytes() != b"STALE-BAK-OLD":
            raise AssertionError("ordrar.php.bak-old saknas i papperskorgen")
        if (parked / "layout.php.bak").read_bytes() != b"sweep-later":
            raise AssertionError("layout.php.bak saknas i papperskorgen")
        collided = [path.read_bytes() for path in parked.glob("ordrar.php.bak-*")]
        if OLD not in collided:
            raise AssertionError(f"bytets .bak skrevs över den gamla kopian: {list(parked.iterdir())}")
        print("PASS gammal .bak flyttas efter sha och deployen fortsätter")
    finally:
        if php is not None:
            php.close()
        if ftp is not None:
            ftp.close()
        shutil.rmtree(home, ignore_errors=True)


def test_stale_bak_gate_still_rolls_back(repo: Path, cert: Path, key: Path, router: Path) -> None:
    """500 ska fortfarande lägga tillbaka live, inte den raderade gamla backupen."""
    home = Path(tempfile.mkdtemp(prefix="jeab-stale-rollback-"))
    ftp = None
    php = None
    stale = b"STALE-BAK-NOT-LIVE\n" * 30
    try:
        seed_live(home)
        bak = home / "src" / "ordrar.php.bak"
        bak.write_bytes(stale)
        handler = make_handler(cert, key, home)
        handler.reject_overwrite = True
        ftp = FtpWorld(home, handler)
        php = PhpWorld(home, router, "crash")
        proc = deploy(repo, home, ftp.port, php.gate, ["--paths", "src/ordrar.php"])
        assert_secrets_hidden(proc, "bak och 500")
        assert_no_live_stor(handler, "bak och 500")
        text = proc.stdout + proc.stderr
        live = (home / "src" / "ordrar.php").read_bytes()
        if proc.returncode == 0 or live != OLD or stale in live:
            raise AssertionError(f"500 återställde inte live exit={proc.returncode} len={len(live)}\n{text}")
        if bak.exists():
            raise AssertionError("backupen ligger kvar efter rollback")
        parked = trash_root(home) / "src" / "ordrar.php.bak"
        if not parked.is_file() or parked.read_bytes() != stale:
            raise AssertionError("500 raderade den gamla backupen i stället för att parkera den")
        _assert_sha_before_stale_delete(text, "bak och 500")
        for needle in ("sha ok via bak", "grind kräver rollback", "återställning", "FEL"):
            if needle not in text:
                raise AssertionError(f"rollback-loggen saknar {needle!r}\n{text}")
        if php.hit_count() != 1:
            raise AssertionError(f"grinden anropades {php.hit_count()} gånger")
        print("PASS gammal .bak tas bort och 500 återställer ändå live")
    finally:
        if php is not None:
            php.close()
        if ftp is not None:
            ftp.close()
        shutil.rmtree(home, ignore_errors=True)


def test_unchanged_with_stale_bak(repo: Path, cert: Path, key: Path, router: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-unchanged-bak-"))
    ftp = None
    php = None
    try:
        seed_live(home)
        (home / "src" / "ordrar.php").write_bytes(NEW)
        (home / "src" / "ordrar.php.bak").write_bytes(b"STALE-UNCHANGED")
        handler = make_handler(cert, key, home)
        handler.reject_overwrite = True
        ftp = FtpWorld(home, handler)
        php = PhpWorld(home, router, "ok")
        proc = deploy(repo, home, ftp.port, php.gate, ["--paths", "src/ordrar.php"])
        text = proc.stdout + proc.stderr
        if proc.returncode != 0 or (home / "src" / "ordrar.php").read_bytes() != NEW:
            raise AssertionError(f"oförändrad fil med .bak stoppades\n{text}")
        if (home / "src" / "ordrar.php.bak").exists():
            raise AssertionError("oförändrad körning lämnade .bak")
        if "oförändrad på servern" not in text or "ett grindanrop" not in text:
            raise AssertionError(f"oförändrad körning saknar grind eller träff\n{text}")
        parked = trash_root(home) / "src" / "ordrar.php.bak"
        if not parked.is_file() or parked.read_bytes() != b"STALE-UNCHANGED":
            raise AssertionError("oförändrad körning raderade .bak i stället för att flytta den")
        bad_rename = [
            (src, dst, status)
            for src, dst, status in handler.rename_log
            if "data/.private/trash/" not in dst.replace("\\", "/")
        ]
        if "Ta bort eller döp om backupen" in text or bad_rename or [name for name in handler.stor_log if name != "deploying.flag"]:
            raise AssertionError(f"oförändrad körning bytte live eller vägrade\n{text}\n{handler.rename_log}")
        flag = home / "private" / "deploying.flag"
        if flag.is_file() and flag.read_bytes().strip() != b"":
            raise AssertionError("oförändrad körning lämnade underhållsflaggan på")
        print("PASS oförändrad fil med gammal .bak deployas och städas efter grön grind")
    finally:
        if php is not None:
            php.close()
        if ftp is not None:
            ftp.close()
        shutil.rmtree(home, ignore_errors=True)


def test_cleanup_bak(repo: Path, cert: Path, key: Path, router: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-cleanup-bak-"))
    ftp = None
    try:
        seed_live(home)
        src = home / "src"
        (src / "ordrar.php.bak").write_bytes(b"STALE")
        (src / "ordrar.php.bak1").write_bytes(b"STALE1")
        (src / "ordrar.php.bak-old").write_bytes(b"STALEOLD")
        (src / "layout.php.bak").write_bytes(b"KEEP-LAYOUT")
        (home / ".htaccess").write_bytes(b"live-htaccess")
        (home / ".htaccess.bak").write_bytes(b"stale-ht")
        (home / ".user.ini.bak1").write_bytes(b"stale-ini")
        other = home / "other"
        other.mkdir()
        (other / "notes.php.bak").write_bytes(b"KEEP-OTHER")
        handler = make_handler(cert, key, home)
        ftp = FtpWorld(home, handler)
        proc = deploy(
            repo,
            home,
            ftp.port,
            "http://127.0.0.1:9/index.php",
            ["--mode", "cleanup-bak", "--paths", "src/ordrar.php.bak,.htaccess"],
        )
        assert_secrets_hidden(proc, "cleanup-bak")
        assert_no_live_stor(handler, "cleanup-bak")
        text = proc.stdout + proc.stderr
        if proc.returncode != 0:
            raise AssertionError(f"cleanup-bak föll\n{text}")
        if (src / "ordrar.php").read_bytes() != OLD or (home / ".htaccess").read_bytes() != b"live-htaccess":
            raise AssertionError("cleanup-bak ändrade live")
        for gone in (src / "ordrar.php.bak", src / "ordrar.php.bak1", src / "ordrar.php.bak-old", home / ".htaccess.bak"):
            if gone.exists():
                raise AssertionError(f"cleanup lämnade {gone.name}")
        if (src / "layout.php.bak").read_bytes() != b"KEEP-LAYOUT":
            raise AssertionError("cleanup tog en annan fils .bak")
        if (home / ".user.ini.bak1").read_bytes() != b"stale-ini":
            raise AssertionError("cleanup tog .user.ini.bak1")
        if (other / "notes.php.bak").read_bytes() != b"KEEP-OTHER":
            raise AssertionError("cleanup tog en orörd mapp")
        if handler.stor_log or handler.rename_log:
            raise AssertionError(f"cleanup skrev eller bytte namn {handler.stor_log} {handler.rename_log}")
        if "ett grindanrop" in text or "byte klart" in text:
            raise AssertionError(f"cleanup bytte eller anropade grinden\n{text}")
        for needle in ("finns redan", "Live byttes inte", "live orörd"):
            if needle not in text:
                raise AssertionError(f"cleanup-loggen saknar {needle!r}\n{text}")
        print("PASS cleanup-bak raderar bara berörda .bak och lämnar live")
    finally:
        if ftp is not None:
            ftp.close()
        shutil.rmtree(home, ignore_errors=True)


def test_cleanup_bak_dry_and_deny(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-cleanup-dry-"))
    try:
        seed_live(home)
        bak = home / "src" / "ordrar.php.bak"
        bak.write_bytes(b"STALE")
        dry = deploy(
            repo,
            home,
            1,
            "http://127.0.0.1:9/index.php",
            ["--mode", "cleanup-bak", "--dry-run", "--paths", "src/ordrar.php"],
        )
        if dry.returncode != 0 or "SKULLE radera src/ordrar.php.bak" not in dry.stdout or "Ingen anslutning" not in dry.stdout:
            raise AssertionError(f"torrkörning av cleanup föll\n{dry.stdout}\n{dry.stderr}")
        if bak.read_bytes() != b"STALE":
            raise AssertionError("torrkörning raderade .bak")
        missing = deploy(repo, repo, 1, "http://127.0.0.1:9/index.php", ["--mode", "cleanup-bak"])
        if missing.returncode == 0 or "cleanup-bak kräver --paths" not in missing.stdout:
            raise AssertionError(f"cleanup utan lista skulle ha stoppats\n{missing.stdout}")
        denied = deploy(
            repo,
            repo,
            1,
            "http://127.0.0.1:9/index.php",
            ["--mode", "cleanup-bak", "--dry-run", "--paths", "data/passwords.json"],
        )
        if denied.returncode == 0 or "får inte städas" not in denied.stdout:
            raise AssertionError(f"cleanup av hemlighet skulle ha nekats\n{denied.stdout}")
        hidden = deploy(
            repo,
            repo,
            1,
            "http://127.0.0.1:9/index.php",
            ["--mode", "cleanup-bak", "--dry-run", "--paths", ".user.ini"],
        )
        if hidden.returncode == 0 or "får inte städas" not in hidden.stdout:
            raise AssertionError(f"cleanup av dold fil skulle ha nekats\n{hidden.stdout}")
        print("PASS cleanup-bak torrkörs, kräver lista och nekar spärrade sökvägar")
    finally:
        shutil.rmtree(home, ignore_errors=True)


def test_tinyfm_stale_bak(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-tfm-stale-"))
    world = None
    new_book = b"<?php\n/* bookings-new */\n"
    committed = False
    try:
        (repo / "src" / "bookings.php").write_bytes(new_book)
        run(["git", "-C", str(repo), "add", "src/bookings.php"])
        run(["git", "-C", str(repo), "commit", "-m", "bookings"])
        committed = True
        seed_live(home)
        (home / "src" / "ordrar.php.bak").write_bytes(b"STALE-ORD")
        (home / "src" / "ordrar.php.bak1").write_bytes(b"STALE-ORD1")
        (home / "src" / "bookings.php").write_bytes(b"<?php\n/* bookings-old */\n")
        (home / "src" / "bookings.php.bak").write_bytes(b"STALE-BOOK")
        (home / "src" / "bookings.php.bak-old").write_bytes(b"STALE-BOOK-OLD")
        other = home / "other"
        other.mkdir()
        (other / "notes.php.bak").write_bytes(b"KEEP-OTHER")
        world = TinyWorld(home)
        proc = deploy(
            repo,
            home,
            1,
            world.gate,
            ["--transport", "tinyfm", "--paths", "src/ordrar.php,src/bookings.php"],
            world.env(),
        )
        assert_tiny_secrets(proc, "tinyfm gammal bak")
        text = proc.stdout + proc.stderr
        if proc.returncode != 0 or (home / "src" / "ordrar.php").read_bytes() != NEW:
            raise AssertionError(f"tinyfm fastnade på gammal .bak\n{text}")
        if (home / "src" / "bookings.php").read_bytes() != new_book:
            raise AssertionError("tinyfm bytte inte bookings.php")
        _assert_sha_before_stale_delete(text, "tinyfm gammal bak")
        for needle in ("sha ok via in-place", "sha ok via bak", "ett grindanrop", "Ange kampanjkod"):
            if needle not in text:
                raise AssertionError(f"tinyfm-loggen saknar {needle!r}\n{text}")
        if "Ta bort eller döp om backupen" in text:
            raise AssertionError(f"tinyfm vägrade i stället för att radera\n{text}")
        for gone in (
            home / "src" / "ordrar.php.bak",
            home / "src" / "ordrar.php.bak1",
            home / "src" / "bookings.php.bak",
            home / "src" / "bookings.php.bak-old",
        ):
            if gone.exists():
                raise AssertionError(f"tinyfm lämnade {gone.name}")
        if (other / "notes.php.bak").read_bytes() != b"KEEP-OTHER":
            raise AssertionError("tinyfm tog en orörd .bak")
        parked = trash_root(home) / "src"
        if (parked / "ordrar.php.bak").read_bytes() != b"STALE-ORD":
            raise AssertionError("tinyfm raderade ordrar.php.bak")
        if (parked / "ordrar.php.bak1").read_bytes() != b"STALE-ORD1":
            raise AssertionError("tinyfm raderade ordrar.php.bak1")
        if (parked / "bookings.php.bak").read_bytes() != b"STALE-BOOK":
            raise AssertionError("tinyfm raderade bookings.php.bak")
        if (parked / "bookings.php.bak-old").read_bytes() != b"STALE-BOOK-OLD":
            raise AssertionError("tinyfm raderade bookings.php.bak-old")
        swapped = [path.read_bytes() for path in parked.glob("bookings.php.bak-*")]
        if b"<?php\n/* bookings-old */\n" not in swapped:
            raise AssertionError("tinyfm skrev över bytets .bak")
        print("PASS tinyfm flyttar gammal .bak och fortsätter med sha och grind")
    finally:
        if world is not None:
            world.close()
        if committed:
            run(["git", "-C", str(repo), "reset", "--hard", "HEAD~1"])
        shutil.rmtree(home, ignore_errors=True)


def test_tinyfm_missing_boot_keeps_bak(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-tfm-missing-bak-"))
    world = None
    try:
        seed_live(home)
        (home / "src" / "ordrar.php").unlink()
        bak = home / "src" / "ordrar.php.bak"
        bak.write_bytes(b"RECOVERY-BAK")
        world = TinyWorld(home)
        proc = deploy(
            repo,
            home,
            1,
            world.gate,
            ["--transport", "tinyfm", "--paths", "src/ordrar.php"],
            world.env(),
        )
        text = proc.stdout + proc.stderr
        if proc.returncode == 0 or "saknas på servern" not in text:
            raise AssertionError(f"saknad boot-fil stoppades inte\n{text}")
        if bak.read_bytes() != b"RECOVERY-BAK" or (home / "src" / "ordrar.php").exists():
            raise AssertionError("saknad live-fil fick sin enda .bak raderad")
        if "Raderar den och fortsätter" in text or "ett grindanrop" in text:
            raise AssertionError(f"saknad fil raderade backup eller anropade grinden\n{text}")
        print("PASS saknad boot-fil behåller .bak")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def test_tinyfm_cleanup_bak(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-tfm-cleanup-"))
    world = None
    try:
        seed_live(home)
        (home / "src" / "ordrar.php.bak").write_bytes(b"STALE-ORD")
        (home / "src" / "ordrar.php.bak1").write_bytes(b"STALE-ORD1")
        (home / "src" / "layout.php.bak").write_bytes(b"KEEP-LAYOUT")
        world = TinyWorld(home)
        proc = deploy(
            repo,
            home,
            1,
            world.gate,
            ["--transport", "tinyfm", "--mode", "cleanup-bak", "--paths", "src/ordrar.php"],
            world.env(),
        )
        assert_tiny_secrets(proc, "tinyfm cleanup")
        text = proc.stdout + proc.stderr
        actions = world.actions()
        if proc.returncode != 0 or (home / "src" / "ordrar.php").read_bytes() != OLD:
            raise AssertionError(f"tinyfm cleanup ändrade live eller föll\n{text}")
        if (home / "src" / "ordrar.php.bak").exists() or (home / "src" / "ordrar.php.bak1").exists():
            raise AssertionError("tinyfm cleanup lämnade .bak")
        if (home / "src" / "layout.php.bak").read_bytes() != b"KEEP-LAYOUT":
            raise AssertionError("tinyfm cleanup tog en annan fils .bak")
        exact = set(actions)
        for banned in ("upload", "rename", "save", "newfile"):
            if banned in exact:
                raise AssertionError(f"tinyfm cleanup gjorde {banned}: {actions}")
        if "delete" not in exact:
            raise AssertionError(f"tinyfm cleanup raderade inte: {actions}")
        if "ett grindanrop" in text or "byte klart" in text:
            raise AssertionError(f"tinyfm cleanup bytte eller körde grind\n{text}")
        print("PASS tinyfm cleanup-bak raderar bara berörda .bak")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def test_bak(repo: Path, cert: Path, key: Path, router: Path) -> None:
    scenario(
        "bak-byte och radering",
        repo,
        cert,
        key,
        router,
        reject_overwrite=True,
        extra=["--paths", "src/ordrar.php"],
        expect_exit=0,
        expect_live=NEW,
        expect_in_log=("via bak", "flyttade", ".bak", "data/.private/trash/", "rester från körningen: inga"),
        require_bak_rename=True,
        expect_hits=1,
        expect_gate_calls=1,
    )


def test_rollback_bak(repo: Path, cert: Path, key: Path, router: Path) -> None:
    scenario(
        "grind 500 återställer bak",
        repo,
        cert,
        key,
        router,
        reject_overwrite=True,
        gate_mode="crash",
        extra=["--paths", "src/ordrar.php"],
        expect_exit=1,
        expect_live=OLD,
        expect_in_log=("via bak", "återställning", "grind kräver rollback", "FEL"),
        require_bak_rename=True,
        expect_hits=1,
        expect_gate_calls=1,
    )


def test_rollback_notext(repo: Path, cert: Path, key: Path, router: Path) -> None:
    scenario(
        "200 utan grindtext återställer bak",
        repo,
        cert,
        key,
        router,
        gate_mode="notext",
        extra=["--paths", "src/ordrar.php"],
        expect_exit=1,
        expect_live=OLD,
        expect_in_log=("via bak", "återställning", "grind kräver rollback", "FEL"),
        require_bak_rename=True,
        expect_hits=1,
        expect_gate_calls=1,
    )


def test_static_gate(repo: Path, cert: Path, key: Path, router: Path) -> None:
    """Smoke-filen verifieras och tas bort efter grön grind. keep_smoke lämnar den kvar."""
    home = Path(tempfile.mkdtemp(prefix="jeab-ftp-"))
    ftp = None
    php = None
    try:
        seed_live(home)
        handler = make_handler(cert, key, home)
        ftp = FtpWorld(home, handler)
        php = PhpWorld(home, router, "ok")
        proc = deploy(repo, home, ftp.port, php.gate, ["--paths", "deploy-check.txt"])
        assert_secrets_hidden(proc, "statisk grind")
        text = proc.stdout + proc.stderr
        if proc.returncode != 0:
            raise AssertionError(text)
        if (home / "deploy-check.txt").exists():
            raise AssertionError(f"deploy-check.txt skulle ha städats bort\n{text}")
        if "(smoke)" not in text or "rester från körningen: inga" not in text:
            raise AssertionError(f"smoke-städning saknas i loggen\n{text}")
        if remnants(home):
            raise AssertionError(f"rester {remnants(home)}")
        if (home / "src" / "ordrar.php").read_bytes() != OLD:
            raise AssertionError("ordrar.php rördes när bara deploy-check.txt skulle upp")
        kept = deploy(repo, home, ftp.port, php.gate, ["--keep-smoke", "--paths", "deploy-check.txt"])
        kept_text = kept.stdout + kept.stderr
        if kept.returncode != 0:
            raise AssertionError(kept_text)
        body = (home / "deploy-check.txt").read_bytes()
        with urllib.request.urlopen(f"http://127.0.0.1:{php.port}/deploy-check.txt", timeout=3) as response:
            served = response.read()
            status = response.status
        if status != 200 or served != body or body != NEW[:64]:
            raise AssertionError(f"statisk fil fel status={status} disk={body!r} http={served!r}")
        if "keep_smoke" not in kept_text:
            raise AssertionError(f"keep_smoke syntes inte\n{kept_text}")
        if php.hit_count() != 2 or proc.stdout.count("ett grindanrop") != 1 or kept.stdout.count("ett grindanrop") != 1:
            raise AssertionError(f"statisk grind anropades fel: hits={php.hit_count()}\n{text}\n{kept_text}")
        print(f"PASS statisk grind: smoke borta efter grön grind, keep_smoke lämnar {len(served)} byte")
    finally:
        if php is not None:
            php.close()
        if ftp is not None:
            ftp.close()
        shutil.rmtree(home, ignore_errors=True)


def test_deploy_requires_paths(repo: Path) -> None:
    proc = deploy(repo, repo, 1, "http://127.0.0.1:9/index.php", [])
    assert_secrets_hidden(proc, "deploy utan lista")
    if proc.returncode == 0 or "deploy kräver --paths" not in proc.stdout:
        raise AssertionError(f"deploy utan lista skulle ha stoppats\n{proc.stdout}\n{proc.stderr}")
    if "börjar " in proc.stdout:
        raise AssertionError("deploy utan lista kontaktade FTP")
    print("PASS deploy utan --paths avbryts före FTP")


def test_mode_deny(repo: Path) -> None:
    check = deploy(repo, repo, 1, "http://127.0.0.1:9/index.php", ["--mode", "check", "--paths", "data/passwords.json"])
    rollback = deploy(
        repo,
        repo,
        1,
        "http://127.0.0.1:9/index.php",
        ["--mode", "rollback", "--dry-run", "--paths", "data/passwords.json"],
    )
    assert_secrets_hidden(check, "check nekad")
    assert_secrets_hidden(rollback, "rollback nekad")
    if check.returncode == 0 or "får inte läsas" not in check.stdout:
        raise AssertionError(f"check av hemlighet skulle ha nekats\n{check.stdout}")
    if rollback.returncode == 0 or "får inte återställas" not in rollback.stdout:
        raise AssertionError(f"rollback av hemlighet skulle ha nekats\n{rollback.stdout}")
    print("PASS check och rollback nekar spärrade sökvägar")


def test_waf_then_rollback(repo: Path, cert: Path, key: Path, router: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-ftp-"))
    ftp = None
    php = None
    try:
        seed_live(home)
        handler = make_handler(cert, key, home)
        ftp = FtpWorld(home, handler)
        php = PhpWorld(home, router, "waf")
        proc = deploy(repo, home, ftp.port, php.gate, ["--paths", "src/ordrar.php"])
        assert_secrets_hidden(proc, "waf")
        assert_no_live_stor(handler, "waf")
        text = proc.stdout + proc.stderr
        live = home / "src" / "ordrar.php"
        bak = home / "src" / "ordrar.php.bak"
        if proc.returncode == 0 or live.read_bytes() != NEW or bak.read_bytes() != OLD:
            raise AssertionError(f"waf lämnade fel läge exit={proc.returncode}\n{text}")
        for needle in ("OKLART", "Loopia-block", "lämnas kvar", "ett grindanrop"):
            if needle not in text:
                raise AssertionError(f"waf-loggen saknar {needle!r}\n{text}")
        if "återställning" in text or php.hit_count() != 1 or text.count("ett grindanrop") != 1:
            raise AssertionError(f"waf rullade tillbaka eller anropade grinden fel\n{text}")
        hits = php.hit_count()
        stor_before = list(handler.stor_log)
        restored = deploy(repo, home, ftp.port, php.gate, ["--mode", "rollback", "--paths", "src/ordrar.php"])
        assert_secrets_hidden(restored, "manuell rollback")
        restored_text = restored.stdout + restored.stderr
        if restored.returncode != 0 or live.read_bytes() != OLD or bak.exists():
            raise AssertionError(f"manuell rollback misslyckades\n{restored_text}")
        if php.hit_count() != hits or "ett grindanrop" in restored_text:
            raise AssertionError(f"rollback anropade grinden\n{restored_text}")
        if "återställning" not in restored_text:
            raise AssertionError(f"rollback saknar återställning\n{restored_text}")
        if remnants(home) or handler.stor_log != stor_before:
            raise AssertionError(f"rollback skrev STOR eller lämnade rester {remnants(home)} {handler.stor_log}")
        print("PASS waf 403 lämnar .bak, manuell rollback lägger tillbaka utan grindanrop")
        for line in text.splitlines():
            if "OKLART" in line or "lämnas kvar" in line:
                print(f"  | {line}")
        for line in restored_text.splitlines():
            if "återställning" in line:
                print(f"  | {line}")
    finally:
        if php is not None:
            php.close()
        if ftp is not None:
            ftp.close()
        shutil.rmtree(home, ignore_errors=True)


def test_check(repo: Path, cert: Path, key: Path, router: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-ftp-"))
    ftp = None
    php = None
    try:
        seed_live(home)
        (home / "src" / "ordrar.php").write_bytes(NEW)
        handler = make_handler(cert, key, home)
        ftp = FtpWorld(home, handler)
        php = PhpWorld(home, router, "ok")
        match = deploy(repo, home, ftp.port, php.gate, ["--mode", "check", "--paths", "src/ordrar.php"])
        assert_secrets_hidden(match, "check match")
        if match.returncode != 0 or "matchar main" not in match.stdout or "LIST src" not in match.stdout:
            raise AssertionError(f"check mot main föll\n{match.stdout}\n{match.stderr}")
        (home / "src" / "ordrar.php").write_bytes(OLD)
        differ = deploy(repo, home, ftp.port, php.gate, ["--mode", "check", "--paths", "src/ordrar.php"])
        if differ.returncode == 0 or "SKILJER sig från main" not in differ.stdout:
            raise AssertionError(f"check skulle ha sett skillnad\n{differ.stdout}")
        missing = deploy(repo, home, ftp.port, php.gate, ["--mode", "check", "--paths", "missing/file.php"])
        if missing.returncode == 0 or "SAKNAS" not in missing.stdout or (home / "missing").exists():
            raise AssertionError(f"check skapade katalog eller missade SAKNAS\n{missing.stdout}")
        if handler.stor_log or handler.rename_log or php.hit_count() != 0:
            raise AssertionError(
                f"check skrev eller anropade grinden stor={handler.stor_log} rename={handler.rename_log} hits={php.hit_count()}"
            )
        print("PASS check: matchar, skiljer, skapar ingen katalog, inget STOR och ingen grind")
        for line in match.stdout.splitlines():
            if "LIVE " in line or line.startswith("LIST"):
                print(f"  | {line}")
    finally:
        if php is not None:
            php.close()
        if ftp is not None:
            ftp.close()
        shutil.rmtree(home, ignore_errors=True)


FIXTURE = REPO / ".github" / "deploy-tests" / "tinyfm_fixture.php"
CAMPAIGN = "kampanj-lokal-inte-riktig"
TINY_USER = "einstein"
TINY_PASS = "tiny-lokal-test-secret"


class TinyWorld:
    def __init__(
        self,
        home: Path,
        mode: str = "ok",
        gate_mode: str = "ok",
        *,
        id_mode: str = "",
        expect_id: str = "",
    ) -> None:
        self.home = home
        self.port = free_port()
        handle, name = tempfile.mkstemp(prefix="jeab-tfm-hits-")
        os.close(handle)
        self.hits = Path(name)
        self.hits.write_text("0", encoding="utf-8")
        handle, name = tempfile.mkstemp(prefix="jeab-tfm-log-")
        os.close(handle)
        self.log_path = Path(name)
        env = os.environ.copy()
        env.update(
            {
                "JEAB_TFM_ROOT": str(home),
                "JEAB_TFM_CAMPAIGN": CAMPAIGN,
                "JEAB_TFM_USER": TINY_USER,
                "JEAB_TFM_PASS": TINY_PASS,
                "JEAB_TFM_MODE": mode,
                "JEAB_GATE_MODE": gate_mode,
                "JEAB_GATE_HITS": str(self.hits),
                "JEAB_TFM_LOG": str(self.log_path),
                "JEAB_TFM_ID_MODE": id_mode,
                "JEAB_TFM_GATE_ID": expect_id,
            }
        )
        self.proc = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{self.port}", str(FIXTURE)],
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )
        deadline = time.time() + 5
        last = ""
        while time.time() < deadline:
            try:
                with urllib.request.urlopen(self.gate, timeout=0.3) as response:
                    response.read()
                self.hits.write_text("0", encoding="utf-8")
                self.log_path.write_text("", encoding="utf-8")
                return
            except Exception as err:
                last = str(err)
                time.sleep(0.05)
        raise RuntimeError(f"tinyfm-fixturen startade inte: {last}")

    def close(self) -> None:
        if self.proc.poll() is None:
            self.proc.terminate()
            try:
                self.proc.wait(timeout=3)
            except subprocess.TimeoutExpired:
                self.proc.kill()
        self.hits.unlink(missing_ok=True)
        self.log_path.unlink(missing_ok=True)

    @property
    def gate(self) -> str:
        return f"http://127.0.0.1:{self.port}/index.php"

    @property
    def filer(self) -> str:
        return f"http://127.0.0.1:{self.port}/filer.php"

    def hit_count(self) -> int:
        try:
            return int(self.hits.read_text(encoding="utf-8").strip() or "0")
        except OSError:
            return -1

    def actions(self) -> list[str]:
        try:
            return [line for line in self.log_path.read_text(encoding="utf-8").splitlines() if line]
        except OSError:
            return []

    def env(self) -> dict[str, str]:
        return {
            "TINYFM_USER": TINY_USER,
            "TINYFM_PASS": TINY_PASS,
            "JEAB_GATE_CODE": CAMPAIGN,
            "JEAB_DEPLOY_TINYFM_URL": self.filer,
            "JEAB_DEPLOY_TINYFM_GAP": "0",
            "JEAB_DEPLOY_GATE_URL": self.gate,
            "LOOPIA_FTP_HOST": "",
            "LOOPIA_FTP_USER": "",
            "LOOPIA_FTP_PASS": "",
        }


def assert_tiny_secrets(proc: subprocess.CompletedProcess[str], label: str) -> None:
    blob = proc.stdout + proc.stderr
    for secret in (CAMPAIGN, TINY_PASS, TINY_USER):
        if secret in blob:
            raise AssertionError(f"{label}: hemlighet syntes i skriptloggen")


def test_login_token_falls_back_to_csrf() -> None:
    spec = importlib.util.spec_from_file_location(
        "tinyfm_client_token", REPO / ".github" / "scripts" / "tinyfm_client.py"
    )
    if spec is None or spec.loader is None:
        raise AssertionError("kan inte ladda tinyfm_client")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    if module.login_token('<input name="token" value="abc">', {"token": "abc"}) != "abc":
        raise AssertionError("input-token ska vinna")
    html = "<script>window.csrf = 'csrf-tok';</script>"
    if module.login_token(html, {}) != "csrf-tok":
        raise AssertionError("window.csrf ska duga när input saknas")
    if module.login_token("<html></html>", {"token": ""}) != "":
        raise AssertionError("tomt värde är inte ett token")
    print("PASS login_token: input, annars window.csrf")


def test_gate_login_fields() -> None:
    spec = importlib.util.spec_from_file_location("tinyfm_client_gate", REPO / ".github" / "scripts" / "tinyfm_client.py")
    if spec is None or spec.loader is None:
        raise AssertionError("kan inte ladda tinyfm_client")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    form = {"_csrf": "abc", "action": "login", "next": "/", "password": ""}
    plain = module.gate_login_fields(form, {"password"}, "koden", "")
    if set(plain) != {"_csrf", "action", "next", "password"} or plain["password"] != "koden":
        raise AssertionError(f"kod-only blev {plain}")
    optional = module.gate_login_fields({**form, "id": ""}, {"password"}, "koden", "")
    if "id" in optional:
        raise AssertionError(f"tomt id skulle ha utelämnats: {optional}")
    filled = module.gate_login_fields({**form, "id": ""}, {"password"}, "koden", "mitt-id")
    if filled.get("id") != "mitt-id" or filled["password"] != "koden":
        raise AssertionError(f"id fylldes inte: {filled}")
    try:
        module.gate_login_fields({**form, "id": ""}, {"password", "id"}, "koden", "")
    except module.TinyFmError as err:
        if "JEAB_GATE_ID" not in str(err):
            raise AssertionError(f"oväntat fel: {err}")
    else:
        raise AssertionError("required id skulle ha stoppat")
    print("PASS grindfält: kod räcker, id bara när formuläret har det")


def test_gate_id_on_fixture(repo: Path) -> None:
    gate_id = "grind-id-lokal-inte-riktig"
    home = Path(tempfile.mkdtemp(prefix="jeab-gate-id-"))
    world = None
    try:
        seed_live(home)
        world = TinyWorld(home, id_mode="optional")
        proc = deploy(repo, home, 1, world.gate, ["--transport", "tinyfm", "--paths", "src/ordrar.php"], world.env())
        assert_tiny_secrets(proc, "grind utan id")
        text = proc.stdout + proc.stderr
        if proc.returncode != 0 or (home / "src" / "ordrar.php").read_bytes() != NEW:
            raise AssertionError(f"kod-only misslyckades\n{text}")
        if "gate-fields:_csrf,action,next,password" not in world.actions():
            raise AssertionError(f"POST-fält: {world.actions()}")
        if any(line.startswith("gate-fields:") and ",id," in f",{line.split(':', 1)[1]}," for line in world.actions()):
            raise AssertionError(f"id skickades utan secret: {world.actions()}")
        world.close()
        world = TinyWorld(home, id_mode="optional", expect_id=gate_id)
        env = world.env()
        env["JEAB_GATE_ID"] = gate_id
        proc = deploy(repo, home, 1, world.gate, ["--transport", "tinyfm", "--paths", "src/ordrar.php"], env)
        blob = proc.stdout + proc.stderr
        if gate_id in blob or proc.returncode != 0:
            raise AssertionError(f"id syntes eller körningen föll\n{blob}")
        if "gate-fields:_csrf,action,id,next,password" not in world.actions():
            raise AssertionError(f"id-fält saknas i POST: {world.actions()}")
        world.close()
        world = TinyWorld(home, id_mode="required")
        before = (home / "src" / "ordrar.php").read_bytes()
        proc = deploy(repo, home, 1, world.gate, ["--transport", "tinyfm", "--paths", "src/ordrar.php"], world.env())
        text = proc.stdout + proc.stderr
        if proc.returncode == 0 or "JEAB_GATE_ID" not in text:
            raise AssertionError(f"required id stoppade inte\n{text}")
        if "kampanjinloggning" in world.actions():
            raise AssertionError(f"POST gjordes utan id: {world.actions()}")
        if (home / "src" / "ordrar.php").read_bytes() != before:
            raise AssertionError("live rördes när id krävdes")
        print("PASS JEAB_GATE_ID är valfri och skickas bara med formulärets id-fält")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def test_gap_constant() -> None:
    spec = importlib.util.spec_from_file_location("tinyfm_client", REPO / ".github" / "scripts" / "tinyfm_client.py")
    if spec is None or spec.loader is None:
        raise AssertionError("kan inte ladda tinyfm_client")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    default = module.TinyFmClient.__init__.__kwdefaults__ or {}
    if default.get("gap", 0) < 3:
        raise AssertionError(f"TinyFM-gap ska vara minst 3 s, fick {default}")
    print("PASS tinyfm: standardgap är minst 3 sekunder")


def test_ftp_probe_stops(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-ftp-stop-"))
    world = None
    try:
        seed_live(home)
        world = TinyWorld(home)
        proc = deploy(
            repo,
            home,
            1,
            world.gate,
            ["--transport", "ftp", "--paths", "src/ordrar.php"],
            {
                "JEAB_DEPLOY_FTP_PROBE_TIMEOUT": "1",
                "JEAB_DEPLOY_TINYFM_URL": world.filer,
                "TINYFM_USER": TINY_USER,
                "TINYFM_PASS": TINY_PASS,
                "JEAB_GATE_CODE": CAMPAIGN,
            },
        )
        text = proc.stdout + proc.stderr
        if proc.returncode == 0 or "stoppar utan tinyfm" not in text or "Ingen live-fil rördes" not in text:
            raise AssertionError(f"ftp skulle ha stoppat\n{text}")
        if "tinyfm-inloggning" in "\n".join(world.actions()):
            raise AssertionError("ftp-läget anropade TinyFM")
        if (home / "src" / "ordrar.php").read_bytes() != OLD:
            raise AssertionError("live-filen rördes när FTP-kontrollen föll")
        print("PASS ftp-kontroll som faller stoppar utan TinyFM och utan att röra live")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def test_missing_ftp_secret_uses_tinyfm(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-no-ftp-secret-"))
    world = None
    try:
        seed_live(home)
        world = TinyWorld(home)
        env = world.env()
        env["LOOPIA_FTP_HOST"] = "127.0.0.1"
        env["LOOPIA_FTP_USER"] = USER
        env["LOOPIA_FTP_PASS"] = ""
        env["LOOPIA_FTP_PORT"] = "1"
        proc = deploy(repo, home, 1, world.gate, ["--transport", "ftp", "--paths", "src/ordrar.php"], env)
        assert_tiny_secrets(proc, "saknad ftp-secret")
        text = proc.stdout + proc.stderr
        live = (home / "src" / "ordrar.php").read_bytes()
        if proc.returncode != 0 or live != NEW:
            raise AssertionError(f"saknad FTP-secret växlade inte till tinyfm\n{text}")
        if "Hoppar över ftp och kör tinyfm" not in text or "Ingen FTP-anslutning gjordes" not in text:
            raise AssertionError(f"loggen saknar hoppet till tinyfm\n{text}")
        if "LOOPIA_FTP_PASS" not in text:
            raise AssertionError(f"loggen namngav inte den saknade secret\n{text}")
        if PASSWORD in text or USER in text:
            raise AssertionError("ftp-hemlighet syntes när secret saknades")
        print("PASS saknad FTP-secret hoppar över ftp och kör tinyfm")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def test_auto_falls_over(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-auto-"))
    world = None
    try:
        seed_live(home)
        world = TinyWorld(home)
        env = world.env()
        env["LOOPIA_FTP_HOST"] = "127.0.0.1"
        env["LOOPIA_FTP_USER"] = USER
        env["LOOPIA_FTP_PASS"] = PASSWORD
        env["LOOPIA_FTP_PORT"] = "1"
        env["JEAB_DEPLOY_FTP_PROBE_TIMEOUT"] = "1"
        proc = deploy(repo, home, 1, world.gate, ["--transport", "auto", "--paths", "src/ordrar.php"], env)
        assert_tiny_secrets(proc, "auto")
        text = proc.stdout + proc.stderr
        live = (home / "src" / "ordrar.php").read_bytes()
        if proc.returncode != 0 or live != NEW or "Växlar till tinyfm" not in text or "Ingen live-fil rördes" not in text:
            raise AssertionError(f"auto växlade inte\n{text}")
        if world.actions().count("tinyfm-inloggning") != 1:
            raise AssertionError(f"fler än en inloggning: {world.actions()}")
        if remnants(home):
            raise AssertionError(f"rester {remnants(home)}")
        print("PASS auto: FTP-kontrollen faller, TinyFM tar över, en inloggning")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def test_tinyfm_upload(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-tfm-"))
    world = None
    try:
        seed_live(home)
        world = TinyWorld(home)
        proc = deploy(
            repo,
            home,
            1,
            world.gate,
            ["--transport", "tinyfm", "--paths", "deploy-check.txt,src/ordrar.php"],
            world.env(),
        )
        assert_tiny_secrets(proc, "tinyfm")
        text = proc.stdout + proc.stderr
        if proc.returncode != 0 or (home / "src" / "ordrar.php").read_bytes() != NEW:
            raise AssertionError(f"tinyfm-upload föll\n{text}")
        flag = home / "private" / "deploying.flag"
        if flag.is_file() and flag.read_bytes().strip() != b"":
            raise AssertionError(f"tinyfm lämnade flaggan på\n{text}")
        if (home / "deploy-check.txt").exists():
            raise AssertionError("deploy-check.txt skulle ha städats bort efter grön grind")
        if "(smoke)" not in text:
            raise AssertionError(f"smoke-städning saknas\n{text}")
        actions = world.actions()
        if actions.count("tinyfm-inloggning") != 1 or actions.count("upload") != 2:
            raise AssertionError(f"fel anrop: {actions}")
        if any(line == "newfile" for line in actions):
            raise AssertionError("uppladdning tog newfile-vägen")
        if text.count("ett grindanrop") != 1 or world.hit_count() != 2:
            raise AssertionError(f"grind fel hits={world.hit_count()}\n{text}")
        if remnants(home) or "raderade" not in text or "via in-place" not in text:
            raise AssertionError(f"på-plats-byte eller rester\n{text}\n{remnants(home)}")
        print("PASS tinyfm: två filer, en session, ett grindanrop, .bak och smoke borta")
        for line in text.splitlines():
            if "tinyfm:" in line or "grind" in line or "raderade" in line or "transport" in line:
                print(f"  | {line}")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def test_tinyfm_newfile(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-tfm-new-"))
    world = None
    try:
        seed_live(home)
        world = TinyWorld(home, mode="noupload")
        proc = deploy(repo, home, 1, world.gate, ["--transport", "tinyfm", "--paths", "src/ordrar.php"], world.env())
        assert_tiny_secrets(proc, "newfile")
        text = proc.stdout + proc.stderr
        actions = world.actions()
        if proc.returncode != 0 or (home / "src" / "ordrar.php").read_bytes() != NEW:
            raise AssertionError(f"newfile-vägen föll\n{text}\n{actions}")
        if "newfile" not in actions or "save" not in actions or "Skapar tom fil" not in text:
            raise AssertionError(f"newfile användes inte\n{text}\n{actions}")
        if actions.count("upload") != 1 or actions.count("tinyfm-inloggning") != 1:
            raise AssertionError(f"retry eller flera inloggningar: {actions}")
        if remnants(home):
            raise AssertionError(f"rester {remnants(home)}")
        print("PASS tinyfm: nekad upload skapar tom fil och sparar en gång")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def test_tinyfm_waf_stops(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-tfm-waf-"))
    world = None
    try:
        seed_live(home)
        world = TinyWorld(home, mode="waf-upload")
        proc = deploy(repo, home, 1, world.gate, ["--transport", "tinyfm", "--paths", "src/ordrar.php"], world.env())
        assert_tiny_secrets(proc, "waf")
        text = proc.stdout + proc.stderr
        actions = world.actions()
        if proc.returncode == 0 or "Stoppar direkt" not in text or "Inga fler" not in text:
            raise AssertionError(f"waf stoppade inte\n{text}")
        if (home / "src" / "ordrar.php").read_bytes() != OLD or (home / "src" / "ordrar.php.bak").exists():
            raise AssertionError("live eller bak rördes vid WAF")
        if actions.count("waf") != 1 or actions.count("upload") != 0:
            raise AssertionError(f"WAF fick ett omförsök: {actions}")
        if "återställning" in text:
            raise AssertionError("WAF rullade tillbaka")
        print("PASS tinyfm: 403 Blocked Page stoppar direkt, live orörd")
        for line in text.splitlines():
            if "OKLART" in line or "Stoppar" in line or "Live-filen" in line:
                print(f"  | {line}")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def test_tinyfm_gate_rollback(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-tfm-roll-"))
    world = None
    try:
        seed_live(home)
        world = TinyWorld(home, gate_mode="crash")
        proc = deploy(repo, home, 1, world.gate, ["--transport", "tinyfm", "--paths", "src/ordrar.php"], world.env())
        text = proc.stdout + proc.stderr
        if proc.returncode == 0 or (home / "src" / "ordrar.php").read_bytes() != OLD:
            raise AssertionError(f"grinden återställde inte\n{text}")
        if "återställning" not in text or "grind kräver rollback" not in text:
            raise AssertionError(f"rollback-logg saknas\n{text}")
        if remnants(home):
            raise AssertionError(f"rester {remnants(home)}")
        print("PASS tinyfm: grind 500 lägger tillbaka föregående innehåll")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def test_tinyfm_check(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-tfm-check-"))
    world = None
    try:
        seed_live(home)
        (home / "src" / "ordrar.php").write_bytes(NEW)
        world = TinyWorld(home)
        proc = deploy(repo, home, 1, world.gate, ["--mode", "check", "--transport", "tinyfm", "--paths", "src/ordrar.php"], world.env())
        text = proc.stdout + proc.stderr
        actions = world.actions()
        if proc.returncode != 0 or "matchar main" not in text:
            raise AssertionError(f"check föll\n{text}\n{actions}")
        if any(line in {"upload", "rename", "delete", "newfile", "save"} for line in actions):
            raise AssertionError(f"check skrev: {actions}")
        if world.hit_count() != 1:
            raise AssertionError(f"check anropade grinden efter inloggning, hits={world.hit_count()}")
        print("PASS tinyfm check: sha mot main, ingen skrivning, ingen listning")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def seed_deploy_junk(home: Path) -> None:
    src = home / "src"
    src.mkdir(parents=True, exist_ok=True)
    (src / ".layout.php.uploading").write_bytes(b"partial-layout")
    (src / ".reminders.php.uploading").write_bytes(b"partial-reminders")
    (src / "file.uploading").write_bytes(b"partial-file")
    (src / "layout.php.bak").write_bytes(b"orphan-layout")
    (src / "backup.php").write_bytes(b"not-a-bak-file")
    (src / "keep.php").write_bytes(b"behall-denna")
    (home / ".htaccess.bak").write_bytes(b"orphan-htaccess")
    (home / ".user.ini.bak1").write_bytes(b"orphan-ini")
    (home / "smoke.txt").write_bytes(b"old-smoke")
    other = home / "other"
    other.mkdir(parents=True, exist_ok=True)
    (other / ".orphan.uploading").write_bytes(b"lamna")
    (other / "notes.php.bak").write_bytes(b"lamna-bak")


def assert_foreign_kept(home: Path) -> None:
    expected = {
        home / "src" / "backup.php": b"not-a-bak-file",
        home / "src" / "keep.php": b"behall-denna",
        home / "other" / ".orphan.uploading": b"lamna",
        home / "other" / "notes.php.bak": b"lamna-bak",
        home / "gone.txt": GONE,
    }
    for path, body in expected.items():
        if not path.is_file() or path.read_bytes() != body:
            raise AssertionError(f"orörd fil ändrades: {path}")


def test_green_sweeps_junk(repo: Path, cert: Path, key: Path, router: Path) -> None:
    """Efter simulerad grön grind finns inget .bak, .uploading eller smoke kvar från körningen."""
    home = Path(tempfile.mkdtemp(prefix="jeab-ftp-junk-"))
    ftp = None
    php = None
    try:
        seed_live(home)
        seed_deploy_junk(home)
        handler = make_handler(cert, key, home)
        ftp = FtpWorld(home, handler)
        php = PhpWorld(home, router, "ok")
        proc = deploy(repo, home, ftp.port, php.gate, ["--paths", "deploy-check.txt,src/ordrar.php"])
        assert_secrets_hidden(proc, "grön städning")
        text = proc.stdout + proc.stderr
        if proc.returncode != 0 or (home / "src" / "ordrar.php").read_bytes() != NEW:
            raise AssertionError(f"grön deploy föll\n{text}")
        gone = [
            home / "deploy-check.txt",
            home / "smoke.txt",
            home / ".htaccess.bak",
            home / ".user.ini.bak1",
            home / "src" / "layout.php.bak",
            home / "src" / "ordrar.php.bak",
            home / "src" / ".layout.php.uploading",
            home / "src" / ".reminders.php.uploading",
            home / "src" / "file.uploading",
        ]
        still = [str(path) for path in gone if path.exists()]
        stray = [path for path in remnants(home) if f"{os.sep}other{os.sep}" not in path]
        if still or stray:
            raise AssertionError(f"skräp kvar {still} {stray}\n{text}")
        assert_foreign_kept(home)
        for needle in (
            "raderade src/.layout.php.uploading",
            "raderade src/.reminders.php.uploading",
            "raderade src/file.uploading",
            "flyttade src/layout.php.bak",
            "flyttade .htaccess.bak",
            "flyttade .user.ini.bak1",
            "raderade deploy-check.txt (smoke)",
            "raderade smoke.txt (smoke)",
            "rester från körningen: inga",
        ):
            if needle not in text:
                raise AssertionError(f"loggen saknar {needle!r}\n{text}")
        parked = trash_root(home)
        expected_park = {
            parked / "src" / "layout.php.bak": b"orphan-layout",
            parked / ".htaccess.bak": b"orphan-htaccess",
            parked / ".user.ini.bak1": b"orphan-ini",
            parked / "src" / "ordrar.php.bak": OLD,
        }
        for path, body in expected_park.items():
            if not path.is_file() or path.read_bytes() != body:
                raise AssertionError(f"papperskorgen saknar {path}")
        if php.hit_count() != 1:
            raise AssertionError(f"grinden anropades {php.hit_count()} gånger")
        print("PASS grön grind: .bak ligger i papperskorgen, .uploading och smoke är borta")
    finally:
        if php is not None:
            php.close()
        if ftp is not None:
            ftp.close()
        shutil.rmtree(home, ignore_errors=True)


def test_failed_gate_keeps_foreign_junk(repo: Path, cert: Path, key: Path, router: Path) -> None:
    """403 och 5xx rör inte .uploading eller andras .bak. 403 behåller körningens .bak."""
    home = Path(tempfile.mkdtemp(prefix="jeab-ftp-wafjunk-"))
    ftp = None
    php = None
    try:
        seed_live(home)
        seed_deploy_junk(home)
        handler = make_handler(cert, key, home)
        ftp = FtpWorld(home, handler)
        php = PhpWorld(home, router, "waf")
        proc = deploy(repo, home, ftp.port, php.gate, ["--paths", "src/ordrar.php"])
        text = proc.stdout + proc.stderr
        live = home / "src" / "ordrar.php"
        bak = home / "src" / "ordrar.php.bak"
        if proc.returncode == 0 or live.read_bytes() != NEW or bak.read_bytes() != OLD:
            raise AssertionError(f"waf lämnade fel läge\n{text}")
        for path in (
            home / "src" / ".layout.php.uploading",
            home / "src" / ".reminders.php.uploading",
            home / "src" / "file.uploading",
            home / "src" / "layout.php.bak",
            home / ".htaccess.bak",
            home / ".user.ini.bak1",
            home / "smoke.txt",
        ):
            if not path.is_file():
                raise AssertionError(f"waf städade {path.name}\n{text}")
        assert_foreign_kept(home)
        if "(smoke)" in text or "raderade src/.layout.php.uploading" in text:
            raise AssertionError(f"waf körde grön städning\n{text}")
        print("PASS 403 lämnar .bak och rör inte .uploading eller andras filer")
    finally:
        if php is not None:
            php.close()
        if ftp is not None:
            ftp.close()
        shutil.rmtree(home, ignore_errors=True)

    home = Path(tempfile.mkdtemp(prefix="jeab-ftp-rolljunk-"))
    ftp = None
    php = None
    try:
        seed_live(home)
        seed_deploy_junk(home)
        handler = make_handler(cert, key, home)
        ftp = FtpWorld(home, handler)
        php = PhpWorld(home, router, "crash")
        proc = deploy(repo, home, ftp.port, php.gate, ["--paths", "src/ordrar.php"])
        text = proc.stdout + proc.stderr
        live = home / "src" / "ordrar.php"
        if proc.returncode == 0 or live.read_bytes() != OLD or (home / "src" / "ordrar.php.bak").exists():
            raise AssertionError(f"rollback lämnade fel läge\n{text}")
        for path in (
            home / "src" / ".layout.php.uploading",
            home / "src" / "layout.php.bak",
            home / ".htaccess.bak",
            home / ".user.ini.bak1",
            home / "smoke.txt",
            home / "other" / ".orphan.uploading",
        ):
            if not path.is_file():
                raise AssertionError(f"rollback städade {path}\n{text}")
        assert_foreign_kept(home)
        if "(smoke)" in text or ".uploading" in text:
            raise AssertionError(f"rollback nämnde städning av skräp\n{text}")
        print("PASS 500 återställer från .bak och lämnar andras skräp")
    finally:
        if php is not None:
            php.close()
        if ftp is not None:
            ftp.close()
        shutil.rmtree(home, ignore_errors=True)


def test_tinyfm_green_sweeps_junk(repo: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-tfm-junk-"))
    world = None
    try:
        seed_live(home)
        seed_deploy_junk(home)
        world = TinyWorld(home)
        proc = deploy(
            repo,
            home,
            1,
            world.gate,
            ["--transport", "tinyfm", "--paths", "deploy-check.txt,src/ordrar.php"],
            world.env(),
        )
        text = proc.stdout + proc.stderr
        if proc.returncode != 0 or (home / "src" / "ordrar.php").read_bytes() != NEW:
            raise AssertionError(f"tinyfm-städning föll\n{text}")
        gone = [
            home / "deploy-check.txt",
            home / "smoke.txt",
            home / ".htaccess.bak",
            home / ".user.ini.bak1",
            home / "src" / ".layout.php.uploading",
            home / "src" / ".reminders.php.uploading",
            home / "src" / "file.uploading",
            home / "src" / "layout.php.bak",
            home / "src" / "ordrar.php.bak",
        ]
        still = [str(path) for path in gone if path.exists()]
        stray = [path for path in remnants(home) if f"{os.sep}other{os.sep}" not in path]
        if still or stray:
            raise AssertionError(f"tinyfm lämnade skräp {still} {stray}\n{text}")
        assert_foreign_kept(home)
        if "raderade src/.layout.php.uploading" not in text or "(smoke)" not in text:
            raise AssertionError(f"tinyfm-loggen saknar städning\n{text}")
        if "flyttade src/layout.php.bak" not in text or "flyttade .htaccess.bak" not in text:
            raise AssertionError(f"tinyfm raderade .bak i stället för att flytta\n{text}")
        parked = trash_root(home)
        expected_park = {
            parked / "src" / "layout.php.bak": b"orphan-layout",
            parked / ".htaccess.bak": b"orphan-htaccess",
            parked / ".user.ini.bak1": b"orphan-ini",
        }
        for path, body in expected_park.items():
            if not path.is_file() or path.read_bytes() != body:
                raise AssertionError(f"tinyfm-papperskorgen saknar {path}")
        print("PASS tinyfm grön grind: .bak ligger i papperskorgen, .uploading och smoke är borta")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def test_upstream_tinyfm(repo: Path) -> None:
    """Riktig Tiny File Manager 2.6, lokal kopia, med samma kampanjgrind."""
    source = Path("/tmp/tinyfilemanager.php")
    if not source.is_file():
        urllib.request.urlretrieve(
            "https://raw.githubusercontent.com/prasathmani/tinyfilemanager/master/tinyfilemanager.php",
            source,
        )
    home = Path(tempfile.mkdtemp(prefix="jeab-tfm-real-"))
    proc_php = None
    try:
        public = home / "public"
        (public / "src").mkdir(parents=True)
        (public / "src" / "ordrar.php").write_bytes(OLD)
        php_hash = subprocess.check_output(
            ["php", "-r", "echo password_hash('tiny-lokal-test-secret', PASSWORD_DEFAULT);"],
            text=True,
        ).strip()
        raw = source.read_text(encoding="utf-8", errors="replace")
        raw = raw.replace(
            "$root_path = $_SERVER['DOCUMENT_ROOT'];",
            "$root_path = $_SERVER['DOCUMENT_ROOT'] . '/public';",
        )
        needle = "'admin' => '$2y$10$/K.hjNr84lLNDt8fTXjoI.DBp6PpeyoJ.mGwrrLuCZfAwfSAGqhOW', //admin@123"
        if needle not in raw:
            raise AssertionError("TinyFM-källan har inte den väntade admin-raden")
        raw = raw.replace(needle, f"'einstein' => '{php_hash}', // local")
        (home / "tinyfilemanager.php").write_text(raw, encoding="utf-8")
        (home / "index.php").write_text(
            textwrap.dedent(
                """\
                <?php
                session_start();
                $campaign = getenv('JEAB_TFM_CAMPAIGN') ?: '';
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'login') {
                    if (hash_equals($campaign, (string) ($_POST['password'] ?? ''))) {
                        $_SESSION['jeab_gate'] = 1;
                        header('Content-Type: application/json');
                        echo '{"ok":true}';
                        exit;
                    }
                    header('Content-Type: application/json');
                    echo '{"ok":false,"error":"Fel kod"}';
                    exit;
                }
                header('Content-Type: text/html; charset=utf-8');
                echo '<label>Ange kampanjkod</label><form method="post"><input type="hidden" name="csrf" value="lokal"><input name="password"></form>';
                """
            ),
            encoding="utf-8",
        )
        (home / "filer.php").write_text(
            "<?php\nsession_start();\nif (empty($_SESSION['jeab_gate'])) {\n"
            "    echo '<label>Ange kampanjkod</label>';\n    exit;\n}\n"
            "require __DIR__ . '/tinyfilemanager.php';\n",
            encoding="utf-8",
        )
        (home / "hook.php").write_text(
            "<?php\n$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');\n"
            "$raw = file_get_contents('php://input');\n"
            "if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $ua === 'curl/8.5' && $raw === '{}') {\n"
            "    http_response_code(401);\nheader('Content-Type: application/json');\n"
            "    echo '{\"ok\":false}';\n    exit;\n}\nhttp_response_code(400);\necho '{\"ok\":false}';\n",
            encoding="utf-8",
        )
        port = free_port()
        env = os.environ.copy()
        env["JEAB_TFM_CAMPAIGN"] = CAMPAIGN
        proc_php = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{port}", "-t", str(home)],
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )
        gate = f"http://127.0.0.1:{port}/index.php"
        deadline = time.time() + 5
        while time.time() < deadline:
            try:
                with urllib.request.urlopen(gate, timeout=0.3) as response:
                    response.read()
                break
            except Exception:
                time.sleep(0.05)
        else:
            raise AssertionError("riktiga TinyFM startade inte")
        proc = deploy(
            repo,
            home,
            1,
            gate,
            ["--transport", "tinyfm", "--paths", "src/ordrar.php"],
            {
                "TINYFM_USER": TINY_USER,
                "TINYFM_PASS": TINY_PASS,
                "JEAB_GATE_CODE": CAMPAIGN,
                "JEAB_DEPLOY_TINYFM_URL": f"http://127.0.0.1:{port}/filer.php",
                "JEAB_DEPLOY_TINYFM_GAP": "0",
                "JEAB_DEPLOY_GATE_URL": gate,
                "LOOPIA_FTP_HOST": "",
                "LOOPIA_FTP_USER": "",
                "LOOPIA_FTP_PASS": "",
            },
        )
        assert_tiny_secrets(proc, "upstream")
        text = proc.stdout + proc.stderr
        live = public / "src" / "ordrar.php"
        if proc.returncode != 0 or live.read_bytes() != NEW:
            raise AssertionError(f"riktiga TinyFM föll\n{text}")
        if list(public.rglob("*.bak")) or list(public.rglob("*.tmp")):
            raise AssertionError("rester i riktiga TinyFM")
        if text.count("en inloggning") != 1 or text.count("ett grindanrop") != 1:
            raise AssertionError(f"session eller grind fel\n{text}")
        print("PASS riktig Tiny File Manager 2.6: upload, på-plats-save, sha, inga rester")
    finally:
        if proc_php is not None and proc_php.poll() is None:
            proc_php.terminate()
            try:
                proc_php.wait(timeout=3)
            except subprocess.TimeoutExpired:
                proc_php.kill()
        shutil.rmtree(home, ignore_errors=True)


def test_list_root_source_has_no_write_commands() -> None:
    """Statisk grep: list-root-funktionerna får inte innehålla skrivkommandon."""
    import ast

    text = SCRIPT.read_text(encoding="utf-8")
    tree = ast.parse(text)
    chunks: list[str] = []
    for node in tree.body:
        if isinstance(node, ast.FunctionDef) and (node.name == "run_list_root" or node.name.startswith("list_root_")):
            segment = ast.get_source_segment(text, node)
            if not segment:
                raise AssertionError(f"kunde inte läsa {node.name}")
            chunks.append(segment)
    if len(chunks) < 5:
        raise AssertionError("list-root-funktionerna saknas")
    body = "\n".join(chunks)
    banned = (
        "STOR",
        "DELE",
        "RNFR",
        "RNTO",
        "MKD",
        "RMD",
        "RETR",
        "storbinary",
        "retrbinary",
        "storlines",
        ".delete(",
        ".rename(",
        ".mkd(",
        ".rmd(",
        "APPE",
        "STOU",
    )
    for token in banned:
        if token in body:
            raise AssertionError(f"list-root innehåller skrivkommando {token}")
    workflow = WORKFLOW.read_text(encoding="utf-8")
    for needle in ("- deploy\n", "- check\n", "- rollback\n", "- cleanup-bak\n", "- list-root\n"):
        if needle not in workflow:
            raise AssertionError(f"workflow saknar läge {needle.strip()}")
    print("PASS list-root: ingen skrivkommando i källan")


def test_list_root_logic() -> None:
    import ftplib

    module = load_module()
    unix_dir = module.list_root_parse_list("drwxr-xr-x 2 u g 4096 Oct 19 12:00 public_html")
    unix_file = module.list_root_parse_list("-rw-r--r-- 1 u g 10 Oct 19 12:00 index.php")
    dos_dir = module.list_root_parse_list("10-19-26  12:00PM       <DIR>          src")
    dos_file = module.list_root_parse_list("10-19-26  12:00PM                 1234 index.php")
    if unix_dir != ("public_html", "dir") or unix_file != ("index.php", "file"):
        raise AssertionError(f"unix LIST tolkades fel: {unix_dir} {unix_file}")
    if dos_dir != ("src", "dir") or dos_file != ("index.php", "file"):
        raise AssertionError(f"dos LIST tolkades fel: {dos_dir} {dos_file}")

    tree = {
        "/": {"dirs": ["flowstack.example.test"], "files": ["rootfile.txt"]},
        "/flowstack.example.test": {"dirs": ["other", "public_html"], "files": []},
        "/flowstack.example.test/other": {"dirs": [], "files": ["secret.txt"]},
        "/flowstack.example.test/public_html": {"dirs": ["src"], "files": ["index.php"]},
        "/flowstack.example.test/public_html/src": {"dirs": ["deep"], "files": ["ordrar.php"]},
        "/flowstack.example.test/public_html/src/deep": {"dirs": ["too-deep"], "files": []},
        "/flowstack.example.test/public_html/src/deep/too-deep": {"dirs": [], "files": []},
    }

    class FakeFTP:
        def __init__(self, start: str) -> None:
            self.path = start
            self.commands: list[str] = []

        def pwd(self) -> str:
            self.commands.append("PWD")
            return self.path

        def cwd(self, name: str) -> str:
            self.commands.append("CWD")
            if name == "..":
                if self.path == "/":
                    raise ftplib.error_perm("550")
                parent = posixpath.dirname(self.path)
                self.path = parent if parent else "/"
                return self.path
            dest = name if name.startswith("/") else posixpath.normpath(posixpath.join(self.path, name))
            if dest not in tree:
                raise ftplib.error_perm("550")
            self.path = dest
            return dest

        def mlsd(self):
            self.commands.append("MLSD")
            node = tree[self.path]
            for name in node["dirs"]:
                yield name, {"type": "dir"}
            for name in node["files"]:
                yield name, {"type": "file"}

    lines: list[str] = []
    original = module.log
    ftp = FakeFTP("/flowstack.example.test/public_html")
    try:
        module.log = lines.append
        module.list_root_climb(ftp)
        module.list_root_walk(ftp, 0)
    finally:
        module.log = original
    text = "\n".join(lines)
    for needle in ("PWD /flowstack.example.test/public_html", "upp /flowstack.example.test", "upp /", "högsta /", "/flowstack.example.test/public_html/src"):
        if needle not in text:
            raise AssertionError(f"list-root saknar {needle}\n{text}")
    if "/flowstack.example.test/public_html/src/deep" in text or "too-deep" in text:
        raise AssertionError(f"list-root gick djupare än 3\n{text}")
    for hidden in ("rootfile.txt", "index.php", "ordrar.php", "secret.txt"):
        if hidden in text:
            raise AssertionError(f"list-root skrev filnamn {hidden}\n{text}")
    allowed = {"PWD", "CWD", "MLSD"}
    if set(ftp.commands) - allowed:
        raise AssertionError(f"oväntade kommandon {set(ftp.commands) - allowed}")

    class Stuck:
        def pwd(self) -> str:
            return "/jailed"

        def cwd(self, name: str) -> None:
            if name != "..":
                raise ftplib.error_perm("550")

        def mlsd(self):
            yield "src", {"type": "dir"}
            if False:
                yield "", {}

    stuck_lines: list[str] = []
    stuck = Stuck()
    try:
        module.log = stuck_lines.append
        module.list_root_climb(stuck)
    finally:
        module.log = original
    stuck_text = "\n".join(stuck_lines)
    if stuck_text.splitlines() != ["PWD /jailed", "högsta /jailed"]:
        raise AssertionError(f"chroot skulle stanna\n{stuck_text}")

    wide = {f"/d{index:03d}": {"dirs": [], "files": []} for index in range(201)}
    wide["/"] = {"dirs": [f"d{index:03d}" for index in range(201)], "files": ["skip-me.php"]}

    class Wide(FakeFTP):
        def mlsd(self):
            self.commands.append("MLSD")
            node = wide[self.path]
            for name in node["dirs"]:
                yield name, {"type": "dir"}
            for name in node["files"]:
                yield name, {"type": "file"}

        def cwd(self, name: str) -> str:
            self.commands.append("CWD")
            if name == "..":
                if self.path == "/":
                    raise ftplib.error_perm("550")
            dest = name if name.startswith("/") else posixpath.normpath(posixpath.join(self.path, name))
            if dest not in wide and dest != "/":
                raise ftplib.error_perm("550")
            self.path = dest if dest in wide else self.path
            return self.path

    wide_lines: list[str] = []
    wide_ftp = Wide("/")
    try:
        module.log = wide_lines.append
        module.list_root_walk(wide_ftp, 0)
    finally:
        module.log = original
    wide_text = "\n".join(wide_lines)
    if "klippt efter 200 poster" not in wide_text or "/d200" in wide_text or "skip-me.php" in wide_text:
        raise AssertionError(f"taket 200 höll inte\n{wide_text}")
    if "/d199" not in wide_text or "kunde inte öppna" in wide_text:
        raise AssertionError(f"första 200 katalogerna saknas\n{wide_text}")
    print("PASS list-root: djup 3, bara kataloger, tak 200, stannar i chroot")


def test_list_root_ftp(repo: Path, cert: Path, key: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-list-root-"))
    world = None
    marker = "UNIQUE-LIST-ROOT-FILE-BYTES"
    try:
        (home / "src" / "nested").mkdir(parents=True)
        (home / "src" / "ordrar.php").write_text(marker + "\n", encoding="utf-8")
        (home / "a" / "b" / "c" / "d").mkdir(parents=True)
        (home / "rootfile.txt").write_text(marker, encoding="utf-8")
        base = make_handler(cert, key, home)

        class Audit(base):
            verbs: list[str] = []

            def process_command(self, cmd, *args, **kwargs):
                Audit.verbs.append(str(cmd).split()[0].upper())
                return super().process_command(cmd, *args, **kwargs)

        Audit.verbs = []
        world = FtpWorld(home, Audit)
        dry = deploy(
            repo,
            home,
            1,
            "http://127.0.0.1:9/index.php",
            ["--mode", "list-root", "--transport", "ftp", "--dry-run"],
        )
        assert_secrets_hidden(dry, "list-root torr")
        if dry.returncode != 0 or "Ingen anslutning görs." not in dry.stdout:
            raise AssertionError(f"list-root torrkörning föll\n{dry.stdout}\n{dry.stderr}")
        missing = deploy(
            repo,
            home,
            1,
            "http://127.0.0.1:9/index.php",
            ["--mode", "list-root", "--transport", "auto"],
            {"LOOPIA_FTP_HOST": "", "LOOPIA_FTP_USER": "", "LOOPIA_FTP_PASS": ""},
        )
        if missing.returncode == 0 or "list-root saknar FTP-secrets" not in missing.stdout:
            raise AssertionError(f"saknade secrets skulle avbryta\n{missing.stdout}\n{missing.stderr}")
        if PASSWORD in (missing.stdout + missing.stderr):
            raise AssertionError("saknade secrets skrev ut lösenordet")
        tiny = deploy(
            repo,
            home,
            world.port,
            "http://127.0.0.1:9/index.php",
            ["--mode", "list-root", "--transport", "tinyfm"],
        )
        if tiny.returncode == 0 or "kräver transport ftp eller auto" not in tiny.stdout:
            raise AssertionError(f"tinyfm skulle avvisas\n{tiny.stdout}\n{tiny.stderr}")
        proc = deploy(
            repo,
            home,
            world.port,
            "http://127.0.0.1:9/index.php",
            ["--mode", "list-root", "--transport", "ftp"],
        )
        assert_secrets_hidden(proc, "list-root")
        text = proc.stdout
        if proc.returncode != 0 or "list-root klar" not in text or "PWD /" not in text or "högsta /" not in text:
            raise AssertionError(f"list-root föll\n{text}\n{proc.stderr}")
        for needle in ("/src", "/src/nested", "/a", "/a/b", "/a/b/c"):
            if needle not in text:
                raise AssertionError(f"list-root saknar {needle}\n{text}")
        if "/a/b/c/d" in text or "ordrar.php" in text or "rootfile.txt" in text or marker in text:
            raise AssertionError(f"list-root visade fil eller för djup katalog\n{text}")
        forbidden = {"STOR", "DELE", "RNFR", "RNTO", "MKD", "RMD", "RETR", "APPE", "STOU"}
        hit = forbidden.intersection(Audit.verbs)
        if hit:
            raise AssertionError(f"list-root skickade {sorted(hit)} verbs={Audit.verbs}")
        if not Audit.verbs:
            raise AssertionError("ingen FTP-session loggades")
        print("PASS list-root: FTP listar bara kataloger, secrets saknas avbryter")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


SERVICE_REDIRECT_BODY = b"RewriteEngine On\nRewriteRule ^ https://www.jarfalla.example.test/ [R=301,L]\n"
SERVICE_REDIRECT_SITE = "/service.jarfalla.example.test"
SERVICE_REDIRECT_PUBLIC = "/service.jarfalla.example.test/public_html"
SERVICE_REDIRECT_FILE = SERVICE_REDIRECT_PUBLIC + "/.htaccess"


def test_service_redirect_source() -> None:
    """Bara tillåtna kommandon och de fasta sökvägarna får finnas i läget."""
    import ast

    text = SCRIPT.read_text(encoding="utf-8")
    tree = ast.parse(text)
    chunks: list[str] = []
    for node in tree.body:
        if isinstance(node, ast.FunctionDef) and (
            node.name == "run_service_redirect" or node.name.startswith("service_redirect_")
        ):
            segment = ast.get_source_segment(text, node)
            if not segment:
                raise AssertionError(f"kunde inte läsa {node.name}")
            chunks.append(segment)
        if isinstance(node, ast.Assign):
            names = [target.id for target in node.targets if isinstance(target, ast.Name)]
            if any(name.startswith("SERVICE_REDIRECT") for name in names):
                segment = ast.get_source_segment(text, node)
                if not segment:
                    raise AssertionError("kunde inte läsa SERVICE_REDIRECT")
                chunks.append(segment)
    if len(chunks) < 8:
        raise AssertionError("service-redirect-funktionerna saknas")
    body = "\n".join(chunks)
    banned = (
        "DELE",
        "RNFR",
        "RNTO",
        "RMD",
        "RETR",
        "APPE",
        "SITE",
        "STOU",
        "retrbinary",
        ".delete(",
        ".rename(",
        ".rmd(",
        "cfg.root",
        "cfg.paths",
        "flowstack.example.test",
        "enter_dir",
        "enter_root",
        "try_delete",
        "retr_bytes",
        "open_transport",
        "LOOPIA_FTP_ROOT",
    )
    for token in banned:
        if token in body:
            raise AssertionError(f"service-redirect innehåller {token}")
    for needle in (
        "MKD",
        "STOR",
        "XSHA256",
        "list_root_climb",
        "deploy/service-redirect.htaccess",
        SERVICE_REDIRECT_SITE,
        SERVICE_REDIRECT_PUBLIC,
        ".htaccess",
    ):
        if needle not in body:
            raise AssertionError(f"service-redirect saknar {needle}")
    allowed_paths = {"/", SERVICE_REDIRECT_SITE, SERVICE_REDIRECT_PUBLIC, SERVICE_REDIRECT_FILE}
    for node in ast.walk(ast.parse(body)):
        if isinstance(node, ast.Constant) and isinstance(node.value, str) and node.value.startswith("/"):
            if node.value not in allowed_paths:
                raise AssertionError(f"otillåten sökväg i läget: {node.value}")
        if isinstance(node, ast.Constant) and isinstance(node.value, str):
            parts = node.value.split("/")
            if any(part == ".." for part in parts) and node.value not in {".", ".."}:
                raise AssertionError(f"sökväg med .. i läget: {node.value}")
    workflow = WORKFLOW.read_text(encoding="utf-8")
    for needle in ("- deploy\n", "- check\n", "- rollback\n", "- cleanup-bak\n", "- list-root\n", "- service-redirect\n"):
        if needle not in workflow:
            raise AssertionError(f"workflow saknar läge {needle.strip()}")
    if "service.jarfalla.example.test" in workflow:
        raise AssertionError("sökvägen får inte vara workflow-input")
    htaccess = (REPO / "deploy" / "service-redirect.htaccess").read_bytes()
    baked = "RewriteEngine On\\nRewriteRule ^ https://www.jarfalla.example.test/ [R=301,L]\\n"
    if htaccess != SERVICE_REDIRECT_BODY or baked not in text:
        raise AssertionError("htaccess-filen och konstanten skiljer sig")
    print("PASS service-redirect: bara fasta kommandon och sökvägar")


def _service_fake(module, support_hash: bool = True):
    import ftplib

    nodes: dict[str, dict] = {
        "/": {"type": "dir"},
        "/flowstack.example.test": {"type": "dir"},
        "/flowstack.example.test/public_html": {"type": "dir"},
        "/flowstack.example.test/public_html/index.php": {"type": "file", "data": b"JEAB-LIVE"},
    }

    class Fake:
        def __init__(self) -> None:
            self.path = "/"
            self.mkds: list[str] = []
            self.stors: list[tuple[str, bytes]] = []
            self.cwds: list[str] = []

        def pwd(self) -> str:
            return self.path

        def cwd(self, name: str) -> str:
            self.cwds.append(name)
            if name == "..":
                if self.path == "/":
                    raise ftplib.error_perm("550")
                parent = posixpath.dirname(self.path)
                self.path = parent or "/"
                return self.path
            dest = name if name.startswith("/") else posixpath.normpath(posixpath.join(self.path, name))
            node = nodes.get(dest)
            if not node or node["type"] != "dir":
                raise ftplib.error_perm("550")
            self.path = dest
            return dest

        def mkd(self, path: str) -> str:
            self.mkds.append(path)
            if path not in {SERVICE_REDIRECT_SITE, SERVICE_REDIRECT_PUBLIC}:
                raise AssertionError(f"otillåten MKD {path}")
            parent = posixpath.dirname(path)
            if nodes.get(parent, {}).get("type") != "dir":
                raise ftplib.error_perm("550")
            nodes[path] = {"type": "dir"}
            return path

        def nlst(self) -> list[str]:
            names = []
            for path, node in nodes.items():
                if node["type"] == "file" and posixpath.dirname(path) == self.path:
                    names.append(posixpath.basename(path))
            return names

        def size(self, name: str) -> int:
            path = name if name.startswith("/") else posixpath.join(self.path, name)
            node = nodes.get(path)
            if not node or node["type"] != "file":
                raise ftplib.error_perm("550")
            return len(node["data"])

        def storbinary(self, cmd: str, fp, blocksize: int = 8192) -> None:
            data = fp.read()
            self.stors.append((cmd, data))
            if cmd != "STOR .htaccess" or self.path != SERVICE_REDIRECT_PUBLIC:
                raise AssertionError(f"otillåten STOR {cmd} i {self.path}")
            nodes[posixpath.join(self.path, ".htaccess")] = {"type": "file", "data": data}

        def voidcmd(self, cmd: str) -> str:
            if cmd == "TYPE I":
                return "200 Type set to I"
            verb, _, arg = cmd.partition(" ")
            if verb in {"XSHA256", "XSHA1", "XSHA", "MD5", "XMD5"}:
                if not support_hash or verb != "XSHA256" or arg != ".htaccess" or self.path != SERVICE_REDIRECT_PUBLIC:
                    raise ftplib.error_perm(f'500 Command "{verb}" not understood.')
                node = nodes[posixpath.join(self.path, ".htaccess")]
                return "213 " + hashlib.sha256(node["data"]).hexdigest()
            raise ftplib.error_perm("500 Command not understood.")

    fake = Fake()
    return fake, nodes


def test_service_redirect_logic() -> None:
    module = load_module()
    payload = module.service_redirect_payload()
    if payload != SERVICE_REDIRECT_BODY:
        raise AssertionError("nyttolasten skiljer sig från filen")
    lines: list[str] = []
    original = module.log
    fake, nodes = _service_fake(module)
    try:
        module.log = lines.append
        module.service_redirect_apply(fake, payload, 32)
    finally:
        module.log = original
    if fake.mkds != [SERVICE_REDIRECT_SITE, SERVICE_REDIRECT_PUBLIC]:
        raise AssertionError(f"fel MKD {fake.mkds}")
    if len(fake.stors) != 1 or fake.stors[0][0] != "STOR .htaccess" or fake.stors[0][1] != payload:
        raise AssertionError(f"fel STOR {fake.stors}")
    if nodes["/flowstack.example.test/public_html/index.php"]["data"] != b"JEAB-LIVE":
        raise AssertionError("flowstack.example.test rördes")
    if any("flowstack.example.test" in name for name in fake.cwds):
        raise AssertionError(f"gick in i flowstack.example.test {fake.cwds}")
    created = len(fake.stors)
    try:
        module.log = lines.append
        module.service_redirect_apply(fake, payload, 32)
    finally:
        module.log = original
    if fake.mkds != [SERVICE_REDIRECT_SITE, SERVICE_REDIRECT_PUBLIC] or len(fake.stors) != created:
        raise AssertionError("omkörning skulle vara en no-op")
    path = SERVICE_REDIRECT_FILE
    mutated = bytearray(payload)
    mutated[0] ^= 0x01
    nodes[path]["data"] = bytes(mutated)
    try:
        module.log = lines.append
        try:
            module.service_redirect_apply(fake, payload, 32)
        except module.DeployError as err:
            if "annat innehåll" not in str(err):
                raise AssertionError(f"fel avbrott {err}") from err
        else:
            raise AssertionError("annat innehåll skulle avbryta")
    finally:
        module.log = original
    if nodes[path]["data"] != bytes(mutated) or len(fake.stors) != created:
        raise AssertionError("annat innehåll skrevs över")
    bare, bare_nodes = _service_fake(module, support_hash=False)
    bare_nodes[SERVICE_REDIRECT_SITE] = {"type": "dir"}
    bare_nodes[SERVICE_REDIRECT_PUBLIC] = {"type": "dir"}
    bare_nodes[path] = {"type": "file", "data": payload}
    try:
        module.log = lines.append
        try:
            module.service_redirect_apply(bare, payload, 32)
        except module.DeployError as err:
            if "kan inte jämföra" not in str(err):
                raise AssertionError(f"saknad hash skulle avbryta, fick {err}") from err
        else:
            raise AssertionError("saknad hash skulle avbryta")
    finally:
        module.log = original
    if bare.stors or bare_nodes[path]["data"] != payload:
        raise AssertionError("saknad hash skrev ändå")
    print("PASS service-redirect: skapar, no-op, annat innehåll och saknad hash avbryter")


def _service_files(root: Path) -> dict[str, bytes]:
    found: dict[str, bytes] = {}
    for path in root.rglob("*"):
        if path.is_file():
            found[str(path.relative_to(root))] = path.read_bytes()
    return found


def _service_commands(lines: list[str]) -> None:
    allowed_verbs = {
        "AUTH",
        "PBSZ",
        "PROT",
        "USER",
        "PASS",
        "ACCT",
        "TYPE",
        "PWD",
        "CWD",
        "CDUP",
        "MKD",
        "STOR",
        "SIZE",
        "NLST",
        "PASV",
        "QUIT",
        "XSHA256",
        "XSHA1",
        "XSHA",
        "MD5",
        "XMD5",
    }
    allowed_cwd = {"/", "..", SERVICE_REDIRECT_SITE, SERVICE_REDIRECT_PUBLIC}
    allowed_mkd = {SERVICE_REDIRECT_SITE, SERVICE_REDIRECT_PUBLIC}
    hash_verbs = {"XSHA256", "XSHA1", "XSHA", "MD5", "XMD5"}
    for line in lines:
        if "flowstack.example.test" in line:
            raise AssertionError(f"kommandot rör flowstack.example.test: {line.split()[0]}")
        verb, _, arg = line.partition(" ")
        verb = verb.upper()
        if verb not in allowed_verbs:
            raise AssertionError(f"otillåtet kommando {verb}")
        if verb == "CWD" and arg not in allowed_cwd:
            raise AssertionError(f"otillåten CWD {arg}")
        if verb == "MKD" and arg not in allowed_mkd:
            raise AssertionError(f"otillåten MKD {arg}")
        if verb == "STOR" and arg != ".htaccess":
            raise AssertionError(f"otillåten STOR {arg}")
        if verb in hash_verbs | {"SIZE"} and arg != ".htaccess":
            raise AssertionError(f"otillåtet argument {verb} {arg}")
        if verb in {"DELE", "RNFR", "RNTO", "RMD", "RETR", "APPE", "SITE"}:
            raise AssertionError(f"förbjudet kommando {verb}")


def test_service_redirect_ftp(repo: Path, cert: Path, key: Path) -> None:
    home = Path(tempfile.mkdtemp(prefix="jeab-service-redirect-"))
    world = None
    marker = b"JEAB-LIVE-DO-NOT-TOUCH"
    try:
        (home / "flowstack.example.test" / "public_html").mkdir(parents=True)
        (home / "flowstack.example.test" / "public_html" / "index.php").write_bytes(marker)
        (home / "kalkyl.jarfalla.example.test" / "public_html").mkdir(parents=True)
        (home / "kalkyl.jarfalla.example.test" / "public_html" / "index.html").write_bytes(b"KALKYL")
        authorizer = DummyAuthorizer()
        authorizer.add_user(USER, PASSWORD, str(home), perm="elradfmwMT")

        class ServiceHandler(TLS_FTPHandler):
            lines: list[str] = []
            proto_cmds = TLS_FTPHandler.proto_cmds.copy()
            proto_cmds["XSHA256"] = dict(
                perm=None,
                auth=True,
                arg=True,
                help="Syntax: XSHA256 <SP> file",
            )

            def found_terminator(self) -> None:
                raw = b"".join(self._in_buffer)
                try:
                    text = self.decode(raw).strip("\r\n")
                except Exception:
                    text = ""
                if text:
                    ServiceHandler.lines.append(text)
                super().found_terminator()

            def ftp_XSHA256(self, path: str) -> None:
                if path != ".htaccess" or self.fs.cwd != SERVICE_REDIRECT_PUBLIC:
                    self.respond("550 XSHA256 nekad")
                    return
                fs_path = self.fs.ftp2fs(path)
                try:
                    data = open(fs_path, "rb").read()
                except OSError:
                    self.respond("550 saknas")
                    return
                self.respond("213 " + hashlib.sha256(data).hexdigest())

            def ftp_STOR(self, file, mode="w"):
                if os.path.basename(file) != ".htaccess":
                    self.respond("550 STOR nekad")
                    return None
                return super().ftp_STOR(file, mode)

            def ftp_MKD(self, path):
                rel = os.path.relpath(path, self.fs.root).replace(os.sep, "/")
                if rel not in {"service.jarfalla.example.test", "service.jarfalla.example.test/public_html"}:
                    self.respond("550 MKD nekad")
                    return None
                return super().ftp_MKD(path)

        ServiceHandler.lines = []
        ServiceHandler.authorizer = authorizer
        ServiceHandler.certfile = str(cert)
        ServiceHandler.keyfile = str(key)
        ServiceHandler.tls_control_required = True
        ServiceHandler.tls_data_required = True
        ServiceHandler.dtp_handler = GuardDTP
        ServiceHandler.ssl_context = None
        world = FtpWorld(home, ServiceHandler)
        root_env = {"LOOPIA_FTP_ROOT": "/flowstack.example.test/public_html"}
        before = _service_files(home)

        def run_mode(extra: list[str], env: dict[str, str] | None = None):
            merged = dict(root_env)
            if env:
                merged.update(env)
            start = len(ServiceHandler.lines)
            proc = deploy(repo, home, world.port, "http://127.0.0.1:9/index.php", extra, merged)
            return proc, ServiceHandler.lines[start:]

        dry, dry_lines = run_mode(["--mode", "service-redirect", "--transport", "ftp", "--dry-run"])
        assert_secrets_hidden(dry, "service-redirect torr")
        if dry.returncode != 0 or "ingen anslutning, ingen MKD, ingen STOR" not in dry.stdout:
            raise AssertionError(f"torrkörning föll\n{dry.stdout}\n{dry.stderr}")
        if dry_lines or _service_files(home) != before:
            raise AssertionError(f"torrkörning ändrade servern {dry_lines}")
        tiny, tiny_lines = run_mode(["--mode", "service-redirect", "--transport", "tinyfm", "--dry-run"])
        if tiny.returncode == 0 or "kräver transport ftp eller auto" not in tiny.stdout or tiny_lines:
            raise AssertionError(f"tinyfm skulle avvisas\n{tiny.stdout}\n{tiny.stderr}")
        missing, missing_lines = run_mode(
            ["--mode", "service-redirect", "--transport", "auto"],
            {"LOOPIA_FTP_HOST": "", "LOOPIA_FTP_USER": "", "LOOPIA_FTP_PASS": ""},
        )
        if missing.returncode == 0 or "saknar FTP-secrets" not in missing.stdout or missing_lines:
            raise AssertionError(f"saknade secrets skulle avbryta\n{missing.stdout}")
        if _service_files(home) != before:
            raise AssertionError("avvisade körningar skapade filer")
        proc, lines = run_mode(["--mode", "service-redirect", "--transport", "auto"])
        assert_secrets_hidden(proc, "service-redirect")
        text = proc.stdout
        target = home / "service.jarfalla.example.test" / "public_html" / ".htaccess"
        if proc.returncode != 0 or "service-redirect klar" not in text or "högsta /" not in text:
            raise AssertionError(f"skapandet föll\n{text}\n{proc.stderr}")
        if not target.is_file() or target.read_bytes() != SERVICE_REDIRECT_BODY:
            raise AssertionError("redirect-filen saknas eller har fel innehåll")
        if f"LIST {SERVICE_REDIRECT_PUBLIC} .htaccess" not in text:
            raise AssertionError(f"listan saknas\n{text}")
        if f"SIZE {SERVICE_REDIRECT_FILE} bytes={len(SERVICE_REDIRECT_BODY)}" not in text:
            raise AssertionError(f"storleken saknas\n{text}")
        if marker.decode() in text or "index.php" in text or "flowstack.example.test" in text:
            raise AssertionError(f"loggen visade en annan sajt\n{text}")
        _service_commands(lines)
        if sum(1 for line in lines if line.upper().startswith("MKD ")) != 2:
            raise AssertionError(f"väntade två MKD\n{lines}")
        if sum(1 for line in lines if line.upper().startswith("STOR ")) != 1:
            raise AssertionError(f"väntade en STOR\n{lines}")
        if (home / "flowstack.example.test" / "public_html" / "index.php").read_bytes() != marker:
            raise AssertionError("flowstack.example.test ändrades")
        if (home / "kalkyl.jarfalla.example.test" / "public_html" / "index.html").read_bytes() != b"KALKYL":
            raise AssertionError("kalkyl ändrades")
        again, again_lines = run_mode(["--mode", "service-redirect", "--transport", "ftp"])
        if again.returncode != 0 or "identisk" not in again.stdout or "service-redirect klar" not in again.stdout:
            raise AssertionError(f"omkörning föll\n{again.stdout}\n{again.stderr}")
        if target.read_bytes() != SERVICE_REDIRECT_BODY:
            raise AssertionError("omkörning ändrade filen")
        _service_commands(again_lines)
        if any(line.upper().startswith(("MKD ", "STOR ")) for line in again_lines):
            raise AssertionError(f"omkörning skrev {again_lines}")
        mutated = bytearray(SERVICE_REDIRECT_BODY)
        mutated[0] ^= 0x01
        target.write_bytes(bytes(mutated))
        differ, differ_lines = run_mode(["--mode", "service-redirect", "--transport", "ftp"])
        if differ.returncode == 0 or "annat innehåll" not in differ.stdout or "Ingen överskrivning" not in differ.stdout:
            raise AssertionError(f"annat innehåll skulle avbryta\n{differ.stdout}\n{differ.stderr}")
        if "service-redirect klar" in differ.stdout:
            raise AssertionError("avbruten körning rapporterades klar")
        if target.read_bytes() != bytes(mutated):
            raise AssertionError("annat innehåll skrevs över")
        _service_commands(differ_lines)
        if any(line.upper().startswith(("MKD ", "STOR ")) for line in differ_lines):
            raise AssertionError(f"avbrottet skrev ändå {differ_lines}")
        if (home / "flowstack.example.test" / "public_html" / "index.php").read_bytes() != marker:
            raise AssertionError("flowstack.example.test ändrades efter avbrott")
        print("PASS service-redirect: torrkörning, skapande, no-op och annat innehåll")
    finally:
        if world is not None:
            world.close()
        shutil.rmtree(home, ignore_errors=True)


def test_private_htaccess_follows_deploy(repo: Path, cert: Path, key: Path, router: Path) -> None:
    """Saknad eller ofullständig private/.htaccess följer med. En färdig spärr lämnas."""
    body = (REPO / "private" / ".htaccess").read_bytes()
    path = repo / "private" / ".htaccess"
    path.parent.mkdir(exist_ok=True)
    path.write_bytes(body)
    run(["git", "-C", str(repo), "add", "private/.htaccess"])
    run(["git", "-C", str(repo), "commit", "-m", "private htaccess"])
    home = Path(tempfile.mkdtemp(prefix="jeab-private-htaccess-"))
    ftp = None
    php = None
    try:
        seed_live(home)
        handler = make_handler(cert, key, home)
        ftp = FtpWorld(home, handler)
        php = PhpWorld(home, router, "ok")
        proc = deploy(repo, home, ftp.port, php.gate, ["--paths", "src/ordrar.php"])
        text = proc.stdout + proc.stderr
        if proc.returncode != 0:
            raise AssertionError(f"deploy med saknad spärr föll\n{text}")
        live = home / "private" / ".htaccess"
        if not live.is_file() or live.read_bytes() != body:
            raise AssertionError(f"saknad private/.htaccess lades inte dit\n{text}")
        if "private/.htaccess saknas eller spärrar inte" not in text:
            raise AssertionError(f"loggen sa inte att spärren saknades\n{text}")
        if handler.stor_log and handler.stor_log[0] == "deploying.flag":
            raise AssertionError(f"vanlig deploy ska inte skriva flaggan först, fick {handler.stor_log}")
        kept = body + b"\n# already denied\n"
        live.write_bytes(kept)
        before = len(handler.stor_log)
        again = deploy(repo, home, ftp.port, php.gate, ["--paths", "src/ordrar.php"])
        again_text = again.stdout + again.stderr
        if again.returncode != 0 or live.read_bytes() != kept:
            raise AssertionError(f"färdig spärr skrevs över\n{again_text}")
        if "private/.htaccess spärrar redan" not in again_text:
            raise AssertionError(f"loggen sa inte att spärren fanns\n{again_text}")
        extra = [name for name in handler.stor_log[before:] if ".htaccess" in name]
        if extra:
            raise AssertionError(f"färdig spärr laddades upp ändå: {extra}")
        live.write_bytes(b"Allow from all\n")
        repaired = deploy(repo, home, ftp.port, php.gate, ["--paths", "src/ordrar.php"])
        repaired_text = repaired.stdout + repaired.stderr
        if repaired.returncode != 0 or live.read_bytes() != body:
            raise AssertionError(f"ofullständig spärr reparerades inte\n{repaired_text}")
        if "private/.htaccess saknas eller spärrar inte" not in repaired_text:
            raise AssertionError(f"reparationen syntes inte i loggen\n{repaired_text}")
        print("PASS private/.htaccess läggs till och repareras, färdig spärr lämnas")
    finally:
        if php is not None:
            php.close()
        if ftp is not None:
            ftp.close()
        shutil.rmtree(home, ignore_errors=True)
        run(["git", "-C", str(repo), "reset", "--hard", "HEAD~1"])


def test_maintenance_flag_is_opt_in() -> None:
    """Vanlig deploy skriver ingen tidsstämpel. --maintenance gör det, och tömmer flaggan efteråt."""
    module = load_module()
    writes: list[bytes] = []

    class Ops:
        def write_flag(self, payload: bytes) -> None:
            writes.append(payload)

    item = module.Item(action="deploy", path="src/ordrar.php")
    module.plan_explicit = lambda cfg: [item]
    module.print_plan = lambda items: None
    module.order_deploy_paths = lambda repo, paths: list(paths)
    module.warn_missing_runtime = lambda cfg, paths: None
    module.apply_private_htaccess = lambda cfg, deploys: deploys
    module.deploy_one = lambda cfg, one: module.Result(one.path, 1, "abc", "ok")
    module.verify_live_against_commit = lambda cfg, deploys: None
    module.read_gate = lambda cfg: ("green", "grinden är grön")
    module.delete_kept_baks = lambda cfg: None
    module.remove_run_junk = lambda cfg: None
    module.print_summary = lambda cfg, items, results: None

    def make_cfg(maintenance: bool) -> object:
        cfg = module.Config(
            repo=".",
            commit="abc",
            base="",
            paths=["src/ordrar.php"],
            dry_run=False,
            from_diff=False,
            insecure=False,
            require_main=False,
            allow_any_commit=True,
            host="h",
            user="u",
            password="p",
            root="/",
            port=21,
            gate_url="http://127.0.0.1",
            needle="n",
            attempts=1,
            delays=[0.0],
            timeout=1.0,
            http_timeout=1.0,
            blocksize=1024,
            maintenance=maintenance,
        )
        cfg.ops = Ops()
        return cfg

    writes.clear()
    if module.run_deploy(make_cfg(False)) != 0:
        raise AssertionError("deploy utan underhåll föll")
    if any(payload.strip() for payload in writes):
        raise AssertionError(f"utan --maintenance skrevs en tidsstämpel: {writes!r}")
    writes.clear()
    if module.run_deploy(make_cfg(True)) != 0:
        raise AssertionError("deploy med underhåll föll")
    stamps = [payload for payload in writes if payload.strip()]
    if len(stamps) != 1 or not stamps[0].strip().isdigit():
        raise AssertionError(f"med --maintenance väntade en tidsstämpel, fick {writes!r}")
    if b"" not in writes:
        raise AssertionError("underhållsflaggan tömdes inte efter uppladdningen")
    print("PASS underhållsflaggan sätts bara med --maintenance")


def main() -> int:
    failures = 0
    tmp = Path(tempfile.mkdtemp(prefix="jeab-deploy-test-"))
    try:
        repo = tmp / "repo"
        repo.mkdir()
        make_git_repo(repo)
        cert, key = make_cert(tmp)
        router = tmp / "router.php"
        write_router(router)
        tests = [
            lambda: test_units(),
            lambda: test_maintenance_flag_is_opt_in(),
            lambda: test_mask_cookie_and_session(),
            lambda: test_workflow_is_manual(),
            lambda: test_dry_run(repo),
            lambda: test_from_diff_zero(repo),
            lambda: test_explicit_deny(repo),
            lambda: test_abort(repo, cert, key, router),
            lambda: test_corrupt(repo, cert, key, router),
            lambda: test_deploy_requires_paths(repo),
            lambda: test_mode_deny(repo),
            lambda: test_static_gate(repo, cert, key, router),
            lambda: test_from_diff(repo, cert, key, router),
            lambda: test_two_files(repo, cert, key, router),
            lambda: test_stale_bak_is_deleted_and_deploy_continues(repo, cert, key, router),
            lambda: test_stale_bak_gate_still_rolls_back(repo, cert, key, router),
            lambda: test_unchanged_with_stale_bak(repo, cert, key, router),
            lambda: test_private_htaccess_follows_deploy(repo, cert, key, router),
            lambda: test_cleanup_bak(repo, cert, key, router),
            lambda: test_cleanup_bak_dry_and_deny(repo),
            lambda: test_tinyfm_stale_bak(repo),
            lambda: test_tinyfm_missing_boot_keeps_bak(repo),
            lambda: test_tinyfm_cleanup_bak(repo),
            lambda: test_bak(repo, cert, key, router),
            lambda: test_green_sweeps_junk(repo, cert, key, router),
            lambda: test_failed_gate_keeps_foreign_junk(repo, cert, key, router),
            lambda: test_rollback_bak(repo, cert, key, router),
            lambda: test_rollback_notext(repo, cert, key, router),
            lambda: test_waf_then_rollback(repo, cert, key, router),
            lambda: test_check(repo, cert, key, router),
            lambda: test_login_token_falls_back_to_csrf(),
            lambda: test_gate_login_fields(),
            lambda: test_gate_id_on_fixture(repo),
            lambda: test_gap_constant(),
            lambda: test_ftp_probe_stops(repo),
            lambda: test_missing_ftp_secret_uses_tinyfm(repo),
            lambda: test_auto_falls_over(repo),
            lambda: test_tinyfm_upload(repo),
            lambda: test_tinyfm_green_sweeps_junk(repo),
            lambda: test_tinyfm_newfile(repo),
            lambda: test_tinyfm_waf_stops(repo),
            lambda: test_tinyfm_gate_rollback(repo),
            lambda: test_tinyfm_check(repo),
            lambda: test_upstream_tinyfm(repo),
            lambda: test_list_root_source_has_no_write_commands(),
            lambda: test_list_root_logic(),
            lambda: test_list_root_ftp(repo, cert, key),
            lambda: test_service_redirect_source(),
            lambda: test_service_redirect_logic(),
            lambda: test_service_redirect_ftp(repo, cert, key),
        ]
        for test in tests:
            try:
                test()
            except Exception as err:
                failures += 1
                print(f"FAIL {err}")
        if failures:
            print(f"{failures} test föll")
            return 1
        print("alla lokala FTP- och grindtester gröna")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
