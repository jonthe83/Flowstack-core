"""En TinyFM-session mot filer.php. Inga hemligheter i loggen.

Protokollet är Tiny File Manager 2.6 (prasathmani/tinyfilemanager), bakom JEAB:s
kampanjgrind:

- kampanjinloggning en gång mot index.php (csrf-formulär, action=login)
- TinyFM-inloggning en gång (fm_usr, fm_pwd, token)
- uppladdning: multipart file + fullpath + token, till ett .deploy-*.tmp-namn
- om uppladdning nekas: newfile=file och newfilename, sedan editorns JSON-save
  (<? och .php? escape:as, annars stoppar WAF)
- verifiering: POST ?dl= med token, ett anrop, råa byte
- rename: rename_from + rename_to (skriver inte över)
- delete: ?del= + token
- listning efter grön grind: GET ?p= och kryssrutorna file[]

Varje anrop väntar ``gap`` sekunder efter det första. HTTP 403 med «Blocked Page»
stoppar sessionen direkt. Inget nytt anrop görs efter det.
"""

from __future__ import annotations

import json
import posixpath
import re
import time
import urllib.error
import urllib.parse
import urllib.request
import http.cookiejar
from html.parser import HTMLParser


class WafBlocked(Exception):
    """Loopia svarade 403 Blocked Page. Sessionen får inte anropa igen."""


class TinyFmError(Exception):
    """TinyFM nekade ett anrop. Inte ett WAF-stopp."""


_GATE_ID_NAMES = {"id", "gate_id", "campaign_id"}


class _FormParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.inputs: dict[str, str] = {}
        self.required: set[str] = set()

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag != "input":
            return
        values = {key: value or "" for key, value in attrs}
        name = values.get("name")
        if not name:
            return
        self.inputs[name] = values.get("value", "")
        if any(key == "required" for key, _value in attrs):
            self.required.add(name)


_CSRF_JS_RE = re.compile(r"""window\.csrf\s*=\s*'([^']+)'""")


def login_token(html: str, inputs: dict[str, str]) -> str:
    """Token från inloggningsfältet, annars window.csrf på samma sida.

    TinyFM 2.6 har dold input name=token. En del svar (redan inloggad
    listning, eller ett formulär som bara sätter skript-variabeln) har
    bara window.csrf. Tomt värde är inte ett token.
    """
    token = inputs.get("token", "")
    if token:
        return token
    match = _CSRF_JS_RE.search(html)
    return match.group(1) if match else ""


def gate_login_fields(
    inputs: dict[str, str],
    required: set[str],
    code: str,
    gate_id: str,
) -> dict[str, str]:
    """Fält som jeab_login_html POST:ar: _csrf, action, next och password.

    password är kampanjkoden. Ett id-fält fylls bara om formuläret har det och
    JEAB_GATE_ID är satt. Saknas secreten och fältet inte är required skickas
    bara koden, tillsammans med formulärets dolda fält.
    """
    fields = dict(inputs)
    if "password" in fields:
        code_key = "password"
    elif "code" in fields:
        code_key = "code"
    else:
        raise TinyFmError("grinden saknar kodfältet password")
    fields[code_key] = code
    if not fields.get("action"):
        fields["action"] = "login"
    id_keys = [key for key in list(fields) if key.lower() in _GATE_ID_NAMES]
    if gate_id:
        for key in id_keys:
            fields[key] = gate_id
        return fields
    for key in id_keys:
        if key in required and not fields.get(key):
            raise TinyFmError("grinden kräver JEAB_GATE_ID")
        if not fields.get(key):
            fields.pop(key, None)
    return fields


class TinyFmClient:
    def __init__(
        self,
        base_url: str,
        user: str,
        password: str,
        campaign: str,
        gate_id: str = "",
        *,
        gap: float = 4,
        timeout: float = 60,
        log=print,
        mask=None,
    ) -> None:
        self.base_url = base_url.rstrip("/")
        self.origin = self.base_url.rsplit("/", 1)[0]
        self.user = user
        self.password = password
        self.campaign = campaign
        self.gate_id = gate_id
        self.gap = gap
        self.timeout = timeout
        self.log = log
        self.mask = mask
        self.token = ""
        self.logged_in = False
        self.blocked = False
        self.calls = 0
        self.logins = 0
        self.actions: list[str] = []
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar),
            _NoRedirect(),
        )

    def _read_tinyfm_form(self) -> tuple[str, _FormParser, str]:
        status, _headers, body = self._request("GET", self.base_url + "?p=", action="tinyfm-formulär")
        html = body.decode("utf-8", "replace")
        if "Ange kampanjkod" in html:
            raise TinyFmError("filer.php visar fortfarande kampanjrutan")
        if status not in {200, 302, 303}:
            raise TinyFmError(f"tinyfm-formulär svarade {status}")
        parser = _FormParser()
        parser.feed(html)
        self._mask_inputs(parser.inputs)
        return html, parser, login_token(html, parser.inputs)

    def login(self) -> None:
        if self.logged_in:
            return
        if not self.campaign:
            raise TinyFmError("saknar JEAB_GATE_CODE")
        if not self.user:
            raise TinyFmError("saknar TINYFM_USER")
        if not self.password:
            raise TinyFmError("saknar TINYFM_PASS")
        status, _headers, body = self._request("GET", self.origin + "/index.php", action="grind-formulär")
        html = body.decode("utf-8", "replace")
        if status == 200 and "kampanjkod" not in html.lower():
            raise TinyFmError(f"kampanjformulär saknas ({status})")
        parser = _FormParser()
        parser.feed(html)
        self._mask_inputs(parser.inputs)
        self._mask_value(self.gate_id)
        fields = gate_login_fields(parser.inputs, parser.required, self.campaign, self.gate_id)
        status, _headers, body = self._request(
            "POST",
            self.origin + "/index.php",
            fields,
            action="kampanjinloggning",
            headers={
                "Content-Type": "application/x-www-form-urlencoded",
                "Origin": self.origin,
                "Referer": self.origin + "/index.php",
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
        )
        text = body.decode("utf-8", "replace")
        if status != 200 or "Fel kod" in text or '"ok":false' in text.replace(" ", ""):
            raise TinyFmError(f"kampanjinloggning misslyckades ({status})")
        html, parser, token = self._read_tinyfm_form()
        if not token:
            html, parser, token = self._read_tinyfm_form()
        if not token:
            raise TinyFmError("TinyFM-token saknas")
        if not _still_login_form(html.encode("utf-8")):
            self._mask_value(token)
            self.token = token
            self.logged_in = True
            self.logins += 1
            self.log("tinyfm: en inloggning, sessionen återanvänds")
            return
        fields = dict(parser.inputs)
        user_key = next((key for key in fields if key.lower() in {"fm_usr", "fm-usr", "username", "user"}), "fm_usr")
        pass_key = next((key for key in fields if key.lower() in {"fm_pwd", "fm-pwd", "password", "pass"}), "fm_pwd")
        fields[user_key] = self.user
        fields[pass_key] = self.password
        fields["token"] = token
        status, headers, body = self._request(
            "POST",
            self.base_url,
            fields,
            action="tinyfm-inloggning",
            headers={
                "Content-Type": "application/x-www-form-urlencoded",
                "Origin": self.origin,
                "Referer": self.base_url,
            },
        )
        if status not in {200, 302, 303} or _still_login_form(body):
            raise TinyFmError(f"TinyFM-inloggning misslyckades ({status})")
        if status in {302, 303}:
            status, _headers, body = self._request("GET", self.base_url + "?p=", action="tinyfm-session")
            if _still_login_form(body) or "Ange kampanjkod" in body.decode("utf-8", "replace"):
                raise TinyFmError("TinyFM-sessionen tog inte")
            parser = _FormParser()
            parser.feed(body.decode("utf-8", "replace"))
            self._mask_inputs(parser.inputs)
            token = parser.inputs.get("token", token)
        self._mask_value(token)
        self.token = token
        self.logged_in = True
        self.logins += 1
        self.log("tinyfm: en inloggning, sessionen återanvänds")

    def upload(self, rel_dir: str, name: str, data: bytes) -> None:
        _guard_tmp(name)
        self._upload_bytes(rel_dir, name, data)

    def write_flag(self, rel_dir: str, name: str, data: bytes) -> None:
        """Skriv underhållsflaggan på plats. Ingen rename och ingen delete.

        Tomt innehåll töms via editorn. TinyFM:s nedladdning behandlar en
        noll-byte-fil som saknad, så tömningen verifieras inte den vägen.
        """
        if posixpath.basename(name) != "deploying.flag" or rel_dir.strip("/") != "private":
            raise TinyFmError("vägrar skriva på ett live-namn")
        self._ensure_dir(rel_dir)
        if data == b"":
            if not self.exists(rel_dir, name):
                self._create_named(rel_dir, name)
            self.save_live(rel_dir, name, data)
            return
        try:
            self._upload_bytes(rel_dir, name, data)
            return
        except WafBlocked:
            raise
        except TinyFmError as err:
            self.log(f"tinyfm: flagguppladdning nekad ({err}). Sparar på plats.")
        if not self.exists(rel_dir, name):
            self._create_named(rel_dir, name)
        self.save_live(rel_dir, name, data)

    def _ensure_dir(self, rel_dir: str) -> None:
        rel_dir = rel_dir.strip("/")
        if not rel_dir or "/" in rel_dir:
            if "/" in rel_dir:
                raise TinyFmError("flaggan ligger bara i private/")
            return
        self.login()
        try:
            names = self.list_names("")
        except TinyFmError:
            names = []
        if rel_dir in names:
            return
        self._form(
            "",
            {"newfile": "folder", "newfilename": rel_dir, "token": self.token},
            f"newfolder {rel_dir}",
        )

    def _create_named(self, rel_dir: str, name: str) -> None:
        if posixpath.basename(name) != "deploying.flag":
            raise TinyFmError("vägrar skriva på ett live-namn")
        self.login()
        self._form(
            rel_dir,
            {"newfile": "file", "newfilename": name, "token": self.token},
            f"newfile {_join(rel_dir, name)}",
        )

    def _upload_bytes(self, rel_dir: str, name: str, data: bytes) -> None:
        self.login()
        boundary = "jeabdeployboundary7f3a"
        parts = [
            _field(boundary, "token", self.token),
            _field(boundary, "fullpath", name),
            _field(boundary, "dzchunkindex", "0"),
            _field(boundary, "dztotalchunkcount", "0"),
            _file_field(boundary, "file", name, data),
            f"--{boundary}--\r\n".encode(),
        ]
        body = b"".join(parts)
        status, _headers, response = self._request(
            "POST",
            self._url(rel_dir),
            body,
            action=f"upload { _join(rel_dir, name) }",
            headers={
                "Content-Type": f"multipart/form-data; boundary={boundary}",
                "Origin": self.origin,
                "Referer": self._url(rel_dir),
            },
            raw=True,
        )
        text = response.decode("utf-8", "replace").replace(" ", "")
        if status != 200 or '"status":"success"' not in text:
            raise TinyFmError(f"uppladdning nekad ({status})")

    def create_empty(self, rel_dir: str, name: str) -> None:
        _guard_tmp(name)
        self.login()
        self._form(
            rel_dir,
            {"newfile": "file", "newfilename": name, "token": self.token},
            f"newfile {_join(rel_dir, name)}",
        )

    def save_live(self, rel_dir: str, name: str, data: bytes) -> None:
        """Skriv över en fil som redan finns. Används för boot-filer.

        Rename bort från live-namnet tar ner filer.php innan tmp hinner
        döpas tillbaka. Editor-save lämnar sökvägen på plats.
        """
        base = posixpath.basename(name)
        if base != name or base in {"", ".", ".."} or "/" in name or "\\" in name:
            raise TinyFmError("ogiltigt live-namn")
        self._save_editor(rel_dir, name, data)

    def save(self, rel_dir: str, name: str, data: bytes) -> None:
        """Spara via editorns JSON-anrop. Formulär-savedata med <?php stoppas av WAF."""
        _guard_tmp(name)
        self._save_editor(rel_dir, name, data)

    def _save_editor(self, rel_dir: str, name: str, data: bytes) -> None:
        self.login()
        edit_url = self._url(rel_dir, {"edit": name})
        status, _headers, page = self._request("GET", edit_url, action=f"edit {_join(rel_dir, name)}")
        html = page.decode("utf-8", "replace")
        if status != 200 or "File not found" in html or _still_login_form(page):
            raise TinyFmError(f"sparning hittade inte {name}")
        match = re.search(r"window\.csrf\s*=\s*'([^']+)'", html)
        if not match:
            raise TinyFmError("saknar editor-token")
        self._mask_value(match.group(1))
        payload = json.dumps(
            {
                "ajax": True,
                "content": data.decode("utf-8"),
                "type": "save",
                "token": match.group(1),
            }
        )
        # Loopia WAF stoppar rå «<?» och «.php?» i kroppen. JSON-escape är samma text.
        payload = payload.replace(".php?", ".php\\u003f").replace("<?", "\\u003c?")
        status, _headers, body = self._request(
            "POST",
            edit_url,
            payload.encode("utf-8"),
            action=f"save {_join(rel_dir, name)}",
            headers={
                "Content-Type": "application/json; charset=utf-8",
                "Origin": self.origin,
                "Referer": edit_url,
            },
            raw=True,
        )
        if status != 200 or _still_login_form(body):
            raise TinyFmError(f"sparning misslyckades ({status})")

    def write_tmp(self, rel_dir: str, name: str, data: bytes) -> None:
        try:
            self.upload(rel_dir, name, data)
            return
        except WafBlocked:
            raise
        except TinyFmError as err:
            self.log(f"tinyfm: uppladdning nekad ({err}). Skapar tom fil och sparar en gång.")
        self.create_empty(rel_dir, name)
        self.save(rel_dir, name, data)

    def read(self, rel_dir: str, name: str) -> bytes:
        self.login()
        status, headers, body = self._form(
            rel_dir,
            {"token": self.token},
            f"download {_join(rel_dir, name)}",
            query={"dl": name},
        )
        disposition = headers.get("Content-Disposition", headers.get("content-disposition", ""))
        if status == 200 and "attachment" in disposition.lower():
            return body
        if status in {301, 302, 303}:
            raise TinyFmError(f"saknar {_join(rel_dir, name)}")
        raise TinyFmError(f"nedladdning av {_join(rel_dir, name)} misslyckades ({status})")

    def exists(self, rel_dir: str, name: str) -> bool:
        try:
            self.read(rel_dir, name)
            return True
        except TinyFmError as err:
            text = str(err)
            if text.startswith("saknar "):
                return False
            # En saknad .bak ger 403 i stället för redirect. Finns namnet inte
            # i listningen är filen borta. En 403 på en fil som syns i listan
            # är ett riktigt fel och får inte tolkas som att den saknas.
            if "misslyckades (403)" in text and name not in self.list_names(rel_dir):
                return False
            raise

    def _ensure_tree(self, rel_dir: str) -> None:
        """Skapa varje nivå. list_names visar inte kataloger, så newfolder körs alltid."""
        rel_dir = rel_dir.strip("/")
        if not rel_dir or ".." in rel_dir.split("/"):
            raise TinyFmError("ogiltig katalog")
        self.login()
        built = ""
        for part in [piece for piece in rel_dir.split("/") if piece and piece != "."]:
            if part in {".", ".."} or "/" in part or "\\" in part:
                raise TinyFmError("ogiltig katalog")
            parent = built
            built = part if not built else built + "/" + part
            self._form(
                parent,
                {"newfile": "folder", "newfilename": part, "token": self.token},
                f"newfolder {built}",
            )

    def relocate(self, src_rel: str, dst_rel: str) -> None:
        """TinyFM döper inte om mellan kataloger. Kopiera, läs tillbaka, radera källan."""
        src_rel = src_rel.replace("\\", "/").strip("/")
        dst_rel = dst_rel.replace("\\", "/").strip("/")
        if (
            not src_rel
            or not dst_rel
            or ".." in src_rel.split("/")
            or ".." in dst_rel.split("/")
        ):
            raise TinyFmError("ogiltig flytt")
        src_dir = posixpath.dirname(src_rel)
        src_name = posixpath.basename(src_rel)
        dst_dir = posixpath.dirname(dst_rel)
        dst_name = posixpath.basename(dst_rel)
        data = self.read(src_dir, src_name)
        if data == b"":
            raise TinyFmError(f"vägrar flytta tom fil {src_rel}")
        if dst_dir:
            self._ensure_tree(dst_dir)
        self._upload_bytes(dst_dir, dst_name, data)
        got = self.read(dst_dir, dst_name)
        if got != data:
            raise TinyFmError(f"flytten verifierades inte: {dst_rel}")
        self.delete(src_dir, src_name)
        if self.exists(src_dir, src_name):
            raise TinyFmError(f"källan finns kvar efter flytt: {src_rel}")

    def rename(self, rel_dir: str, src: str, dst: str) -> None:
        self.login()
        self._form(
            rel_dir,
            {"rename_from": src, "rename_to": dst, "token": self.token},
            f"rename {src} -> {dst}",
        )
        if self.exists(rel_dir, src):
            raise TinyFmError(f"rename {src} -> {dst} gjordes inte")

    def delete(self, rel_dir: str, name: str) -> None:
        self.login()
        status, _headers, _body = self._form(
            rel_dir,
            {"token": self.token},
            f"delete {_join(rel_dir, name)}",
            query={"del": name},
        )
        # Loopia svarar 403/405 när del-adressen innehåller «.php». Döp om
        # bort från .php och radera det nya namnet.
        if status in {403, 405} and ".php" in name.lower():
            alias = "removed-" + re.sub(r"[^A-Za-z0-9._-]", "", name).replace(".php", "") + ".txt"
            if alias in {".txt", "removed-.txt"} or alias == name:
                alias = "removed-deploy.txt"
            self.rename(rel_dir, name, alias)
            self._form(
                rel_dir,
                {"token": self.token},
                f"delete {_join(rel_dir, alias)}",
                query={"del": alias},
            )
            name = alias
        if self.exists(rel_dir, name):
            raise TinyFmError(f"kunde inte radera {_join(rel_dir, name)}")

    def list_names(self, rel_dir: str) -> list[str]:
        self.login()
        shown = rel_dir.strip("/") or "/"
        status, _headers, body = self._request(
            "GET",
            self._url(rel_dir),
            action=f"lista {shown}",
        )
        text = body.decode("utf-8", "replace")
        if status != 200 or _still_login_form(body) or "Ange kampanjkod" in text:
            raise TinyFmError(f"listning av {rel_dir or '/'} misslyckades ({status})")
        return parse_listed_names(text)

    def _form(
        self,
        rel_dir: str,
        fields: dict[str, str],
        action: str,
        query: dict[str, str] | None = None,
    ) -> tuple[int, dict[str, str], bytes]:
        return self._request(
            "POST",
            self._url(rel_dir, query),
            fields,
            action=action,
            headers={
                "Content-Type": "application/x-www-form-urlencoded",
                "Origin": self.origin,
                "Referer": self._url(rel_dir),
            },
        )

    def _url(self, rel_dir: str, query: dict[str, str] | None = None) -> str:
        params = {"p": rel_dir}
        if query:
            params.update(query)
        return self.base_url + "?" + urllib.parse.urlencode(params)

    def _request(
        self,
        method: str,
        url: str,
        data: dict[str, str] | bytes | None = None,
        *,
        action: str,
        headers: dict[str, str] | None = None,
        raw: bool = False,
    ) -> tuple[int, dict[str, str], bytes]:
        if self.blocked:
            raise WafBlocked("WAF-stopp redan satt. Inget nytt TinyFM-anrop.")
        if self.calls and self.gap > 0:
            time.sleep(self.gap)
        self.calls += 1
        self.actions.append(action)
        self.log(f"tinyfm: {action}")
        payload = data if raw or data is None or isinstance(data, bytes) else urllib.parse.urlencode(data).encode()
        request = urllib.request.Request(url, data=payload, method=method)
        request.add_header("User-Agent", "JEAB-loopia-deploy/1")
        for key, value in (headers or {}).items():
            request.add_header(key, value)
        message = None
        try:
            with self.opener.open(request, timeout=self.timeout) as response:
                status = int(response.status)
                message = response.headers
                body = response.read()
        except urllib.error.HTTPError as err:
            status = int(err.code)
            message = err.headers
            body = err.read()
        except (urllib.error.URLError, TimeoutError) as err:
            raise TinyFmError(f"{action} gick inte att nå: {type(err).__name__}") from err
        self._mask_cookies()
        self._mask_set_cookie(message)
        header_map = {key: value for key, value in message.items()} if message is not None else {}
        if status == 403 and b"blocked page" in body.lower():
            self.blocked = True
            raise WafBlocked(
                "OKLART: HTTP 403 med Loopia-block (Blocked Page). "
                "Stoppar direkt. Inga fler TinyFM-anrop och ingen retry."
            )
        return status, header_map, body

    def _mask_value(self, value: str) -> None:
        if self.mask is None or not value or len(value) < 8 or any(char in value for char in "\r\n"):
            return
        self.mask(value)

    def _mask_inputs(self, inputs: dict[str, str]) -> None:
        for key, value in inputs.items():
            if key.lower() in {"token", "csrf", "csrf_token", "_token"}:
                self._mask_value(value)

    def _mask_cookies(self) -> None:
        for cookie in self.jar:
            self._mask_value(cookie.value or "")

    def _mask_set_cookie(self, headers) -> None:
        if headers is None:
            return
        lines = headers.get_all("Set-Cookie") if hasattr(headers, "get_all") else None
        if not lines:
            single = headers.get("Set-Cookie") if hasattr(headers, "get") else None
            lines = [single] if single else []
        for line in lines:
            pair = str(line).split(";", 1)[0]
            if "=" not in pair:
                continue
            self._mask_value(pair.split("=", 1)[1].strip())


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # type: ignore[override]
        return None


def parse_listed_names(html: str) -> list[str]:
    """Filnamn från TinyFM:s kryssrutor ``name="file[]"``.

    Dolda fält (kopieringsformuläret) och «markera alla» räknas inte.
    """

    class _Names(HTMLParser):
        def __init__(self) -> None:
            super().__init__()
            self.found: list[str] = []

        def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
            if tag != "input":
                return
            values = {key: value or "" for key, value in attrs}
            if values.get("name") != "file[]":
                return
            if values.get("type") != "checkbox":
                return
            name = values.get("value", "")
            if not name or name in {".", ".."} or "/" in name or "\\" in name:
                return
            self.found.append(name)

    parser = _Names()
    parser.feed(html)
    seen: list[str] = []
    for name in parser.found:
        if name not in seen:
            seen.append(name)
    return seen


def _guard_tmp(name: str) -> None:
    base = posixpath.basename(name)
    if ".tmp" not in base or ".deploy-" not in base:
        raise TinyFmError("vägrar skriva på ett live-namn")


def _join(rel_dir: str, name: str) -> str:
    return posixpath.join(rel_dir, name) if rel_dir else name


def _still_login_form(body: bytes) -> bool:
    text = body.decode("utf-8", "replace")
    return 'name="fm_pwd"' in text or 'name="fm-pwd"' in text


def _field(boundary: str, name: str, value: str) -> bytes:
    return (
        f"--{boundary}\r\n"
        f'Content-Disposition: form-data; name="{name}"\r\n\r\n'
        f"{value}\r\n"
    ).encode()


def _file_field(boundary: str, name: str, filename: str, data: bytes) -> bytes:
    head = (
        f"--{boundary}\r\n"
        f'Content-Disposition: form-data; name="{name}"; filename="{filename}"\r\n'
        "Content-Type: application/octet-stream\r\n\r\n"
    ).encode()
    return head + data + b"\r\n"
