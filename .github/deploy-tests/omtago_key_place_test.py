#!/usr/bin/env python3
"""Nyckeln skrivs till privat/ och syns inte i utskriften.

Inloggningen går genom loopia_deploy.connect_with_credentials. Testet
använder påhittade uppgifter och öppnar ingen socket.
"""

from __future__ import annotations

import argparse
import io
import os
import ssl
import sys
import unittest
from ftplib import error_perm
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import loopia_deploy  # noqa: E402
import omtago_key_place as place  # noqa: E402

ENV_KEYS = (
    "LOOPIA_FTP_HOST",
    "LOOPIA_FTP_USER",
    "LOOPIA_FTP_PASS",
    "LOOPIA_FTP_PORT",
    "LOOPIA_FTP_ROOT",
    "JEAB_DEPLOY_TIMEOUT",
    "OMTAGO_API_KEY",
    "JEAB_TICK_TOKEN",
    "JEAB_PLACE_DEST",
    "JEAB_PLACE_SECRET_ENV",
)


class FakeFTP:
    def __init__(self) -> None:
        self.cwd_now = "/"
        self.dirs = {"/", "/flowstack.example.test", "/flowstack.example.test/public_html"}
        self.files: dict[str, bytes] = {}
        self.commands: list[str] = []

    def pwd(self) -> str:
        return self.cwd_now

    def cwd(self, path: str) -> None:
        if path == "/":
            self.cwd_now = "/"
            return
        if path.startswith("/"):
            target = path.rstrip("/") or "/"
        else:
            target = os.path.normpath(os.path.join(self.cwd_now, path)).replace("\\", "/")
            if not target.startswith("/"):
                target = "/" + target
        if target not in self.dirs:
            raise error_perm("550 saknas")
        self.cwd_now = target

    def mkd(self, name: str) -> None:
        path = name if name.startswith("/") else os.path.normpath(os.path.join(self.cwd_now, name)).replace("\\", "/")
        self.dirs.add(path.rstrip("/"))

    def storbinary(self, command: str, source: io.BytesIO, blocksize: int = 8192) -> None:
        del blocksize
        self.commands.append(command)
        name = command.split()[-1]
        path = os.path.normpath(os.path.join(self.cwd_now, name)).replace("\\", "/")
        self.files[path] = source.read()

    def voidcmd(self, command: str) -> str:
        self.commands.append(command)
        return "200 ok"

    def size(self, name: str) -> int:
        path = os.path.normpath(os.path.join(self.cwd_now, name)).replace("\\", "/")
        return len(self.files[path])

    def quit(self) -> None:
        return None


class RecordingFTP(FakeFTP):
    """Ersätter SessionFTP. Ingen riktig anslutning."""

    def __init__(self, context: ssl.SSLContext) -> None:
        super().__init__()
        self.context = context
        self.host = ""
        self.encoding = ""
        self.sock = self.Sock()
        self.login_args: tuple[str, str] | None = None
        self.calls: list[object] = []
        self.closed = False

    class Sock:
        def __init__(self) -> None:
            self.timeout: float | None = None

        def settimeout(self, value: float) -> None:
            self.timeout = value

    def connect(self, host: str, port: int, timeout: float | None = None) -> None:
        self.host = host
        self.calls.append(("connect", host, port, timeout))

    def login(self, user: str, password: str) -> None:
        self.login_args = (user, password)
        self.calls.append(("login", user, password))

    def prot_p(self) -> None:
        self.calls.append(("prot_p",))

    def set_pasv(self, flag: bool) -> None:
        self.calls.append(("pasv", flag))

    def voidcmd(self, command: str) -> str:
        self.calls.append(command)
        return super().voidcmd(command)

    def close(self) -> None:
        self.closed = True


class RejectingFTP(RecordingFTP):
    def login(self, user: str, password: str) -> None:
        self.login_args = (user, password)
        raise error_perm("530 Login incorrect.")


class PlaceTests(unittest.TestCase):
    def setUp(self) -> None:
        self._saved = {key: os.environ.get(key) for key in ENV_KEYS}
        for key in ENV_KEYS:
            os.environ.pop(key, None)
        self._session = loopia_deploy.SessionFTP
        loopia_deploy.reset_secrets()

    def tearDown(self) -> None:
        loopia_deploy.SessionFTP = self._session
        loopia_deploy.reset_secrets()
        for key, value in self._saved.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value

    def test_skriver_syskon_till_public_html_utan_att_visa_nyckeln(self) -> None:
        secret = "hemlig-omtago-nyckel-7535"
        os.environ["LOOPIA_FTP_ROOT"] = "/flowstack.example.test/public_html"
        ftp = FakeFTP()
        stdout = io.StringIO()
        previous = sys.stdout
        sys.stdout = stdout
        try:
            code = place.place(ftp, secret)
        finally:
            sys.stdout = previous
        self.assertEqual(code, 0)
        stored = ftp.files["/flowstack.example.test/privat/omtago_api_key"]
        self.assertEqual(stored, (secret + "\n").encode())
        self.assertIn("SITE CHMOD 600 omtago_api_key", ftp.commands)
        self.assertNotIn("/flowstack.example.test/public_html/privat/omtago_api_key", ftp.files)
        text = stdout.getvalue()
        self.assertNotIn(secret, text)
        self.assertIn("bytes=", text)

    def test_vagrar_rot_utan_public_html(self) -> None:
        os.environ["LOOPIA_FTP_ROOT"] = "/flowstack.example.test"
        with self.assertRaises(place.PlaceError):
            place.place(FakeFTP(), "annan-nyckel")

    def test_saknad_secret_skriver_inget(self) -> None:
        code = place.main()
        self.assertEqual(code, 1)

    def test_tick_token_hamnar_i_egen_fil(self) -> None:
        secret = "tick-token-ska-inte-synas-8841"
        os.environ["LOOPIA_FTP_ROOT"] = "/flowstack.example.test/public_html"
        os.environ["JEAB_PLACE_DEST"] = "jeab_tick_token"
        os.environ["JEAB_PLACE_SECRET_ENV"] = "JEAB_TICK_TOKEN"
        ftp = FakeFTP()
        stdout = io.StringIO()
        previous = sys.stdout
        sys.stdout = stdout
        try:
            code = place.place(ftp, secret)
        finally:
            sys.stdout = previous
        self.assertEqual(code, 0)
        self.assertEqual(ftp.files["/flowstack.example.test/privat/jeab_tick_token"], (secret + "\n").encode())
        self.assertNotIn("omtago_api_key", ftp.files["/flowstack.example.test/privat/jeab_tick_token"].decode())
        self.assertIn("SITE CHMOD 600 jeab_tick_token", ftp.commands)
        self.assertNotIn("/flowstack.example.test/public_html/privat/jeab_tick_token", ftp.files)
        self.assertNotIn(secret, stdout.getvalue())

    def test_inloggning_ar_deployens_och_trimmar_utan_att_skriva_ut(self) -> None:
        padded_host = " ftp.example.test\n"
        padded_user = " placera-anvandare\r\n"
        padded_password = " placera-losen-991\n"
        secret = "omtago-nyckel-trim-441"
        os.environ["LOOPIA_FTP_HOST"] = padded_host
        os.environ["LOOPIA_FTP_USER"] = padded_user
        os.environ["LOOPIA_FTP_PASS"] = padded_password
        os.environ["LOOPIA_FTP_PORT"] = "2121"
        os.environ["JEAB_DEPLOY_TIMEOUT"] = "15"
        os.environ["LOOPIA_FTP_ROOT"] = "/flowstack.example.test/public_html"
        os.environ["OMTAGO_API_KEY"] = secret
        created: list[RecordingFTP] = []

        def session(context: ssl.SSLContext) -> RecordingFTP:
            ftp = RecordingFTP(context)
            created.append(ftp)
            return ftp

        loopia_deploy.SessionFTP = session  # type: ignore[misc]
        stdout = io.StringIO()
        stderr = io.StringIO()
        previous_out, previous_err = sys.stdout, sys.stderr
        sys.stdout, sys.stderr = stdout, stderr
        try:
            code = place.main()
            cfg = loopia_deploy.load_config(
                argparse.Namespace(
                    gate_url="",
                    repo=".",
                    base="",
                    paths=[],
                    dry_run=False,
                    from_diff=False,
                    mode="deploy",
                    insecure=False,
                    require_main=False,
                    allow_any_commit=False,
                    needle="",
                    transport="ftp",
                    keep_smoke=False,
                )
            )
        finally:
            sys.stdout, sys.stderr = previous_out, previous_err
        text = stdout.getvalue() + stderr.getvalue()
        self.assertEqual(code, 0)
        self.assertEqual(len(created), 1)
        ftp = created[0]
        self.assertEqual(ftp.login_args, ("placera-anvandare", "placera-losen-991"))
        self.assertEqual(
            ftp.calls[:4],
            [
                ("connect", "ftp.example.test", 2121, 15.0),
                ("login", "placera-anvandare", "placera-losen-991"),
                ("prot_p",),
                ("pasv", True),
            ],
        )
        self.assertIn("TYPE I", ftp.calls)
        self.assertEqual(ftp.encoding, "utf-8")
        self.assertEqual(ftp.sock.timeout, 15.0)
        self.assertEqual(ftp.context.verify_mode, ssl.CERT_REQUIRED)
        self.assertTrue(ftp.context.check_hostname)
        self.assertIs(place.connect_with_credentials, loopia_deploy.connect_with_credentials)
        self.assertEqual(cfg.host, "ftp.example.test")
        self.assertEqual(cfg.user, "placera-anvandare")
        self.assertEqual(cfg.password, "placera-losen-991")
        self.assertEqual(cfg.port, 2121)
        self.assertEqual(cfg.timeout, 15.0)
        self.assertEqual(ftp.files["/flowstack.example.test/privat/omtago_api_key"], (secret + "\n").encode())
        for hidden in (padded_host, padded_user, padded_password, secret, "placera-losen-991", "placera-anvandare"):
            self.assertNotIn(hidden, text)
        self.assertNotIn("FTP_TLS(", Path(place.__file__).read_text(encoding="utf-8"))

    def test_nekad_inloggning_skriver_inte_uppgifterna(self) -> None:
        password = "nekad-losen-773"
        user = "nekad-anvandare"
        os.environ["LOOPIA_FTP_HOST"] = " ftp.example.test "
        os.environ["LOOPIA_FTP_USER"] = f" {user}\n"
        os.environ["LOOPIA_FTP_PASS"] = f"\n{password} "
        os.environ["OMTAGO_API_KEY"] = "nyckel-som-inte-far-synas"
        loopia_deploy.SessionFTP = RejectingFTP  # type: ignore[misc]
        stderr = io.StringIO()
        previous = sys.stderr
        sys.stderr = stderr
        try:
            code = place.main()
        finally:
            sys.stderr = previous
        self.assertEqual(code, 1)
        text = stderr.getvalue()
        self.assertIn("FTP-inloggning nekades. Ingen fil skrevs.", text)
        self.assertNotIn(password, text)
        self.assertNotIn(user, text)
        self.assertNotIn("nyckel-som-inte-far-synas", text)

    def test_tomma_uppgifter_efter_trim_ansluter_inte(self) -> None:
        os.environ["LOOPIA_FTP_HOST"] = " \n"
        os.environ["LOOPIA_FTP_USER"] = " nagon "
        os.environ["LOOPIA_FTP_PASS"] = " \r\n"
        os.environ["OMTAGO_API_KEY"] = "finns"
        called = False

        def session(context: ssl.SSLContext) -> RecordingFTP:
            nonlocal called
            called = True
            return RecordingFTP(context)

        loopia_deploy.SessionFTP = session  # type: ignore[misc]
        code = place.main()
        self.assertEqual(code, 1)
        self.assertFalse(called)


if __name__ == "__main__":
    unittest.main()
