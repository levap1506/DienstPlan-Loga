#!/usr/bin/env python3
"""Client HTTP direct pentru LOGA3 (fara Selenium sau Playwright).

Functii:
* autentificare GWT-RPC in trei pasi si obtinerea tokenului XSRF;
* sincronizarea documentelor generate prin API-urile LOGA;
* descarcarea datelor lunare direct din API si generarea celor doua PDF-uri;
* deduplicare si detectarea modificarilor cu SHA-256.

Parola este citita din mediu sau ceruta interactiv si nu este scrisa pe disc.
"""

from __future__ import annotations

import argparse
import calendar
import getpass
import hashlib
import html
import json
import logging
import os
import re
import shutil
import sys
import tempfile
import time
import uuid
from dataclasses import asdict, dataclass, field
from datetime import date, datetime, time as datetime_time
from pathlib import Path
from typing import Any, Iterable, Iterator, Mapping
from urllib.parse import quote, urljoin, urlparse
from zoneinfo import ZoneInfo

try:
    import requests
    from requests import Response, Session
    from requests.adapters import HTTPAdapter
    from urllib3.util.retry import Retry
except ImportError:  # self-test ramane disponibil inainte de instalarea dependintelor
    requests = None  # type: ignore[assignment]
    Response = Any  # type: ignore[misc,assignment]
    Session = Any  # type: ignore[misc,assignment]
    HTTPAdapter = None  # type: ignore[assignment]
    Retry = None  # type: ignore[assignment]

try:
    from reportlab.lib import colors
    from reportlab.lib.enums import TA_CENTER
    from reportlab.lib.pagesizes import A4, landscape
    from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
    from reportlab.lib.units import mm
    from reportlab.pdfgen import canvas as pdf_canvas
    from reportlab.platypus import (
        Paragraph,
        SimpleDocTemplate,
        Spacer,
        Table,
        TableStyle,
    )
except ImportError:  # mesaj explicit la comanda monthly/all
    colors = None  # type: ignore[assignment]
    TA_CENTER = 1  # type: ignore[assignment]
    A4 = landscape = ParagraphStyle = getSampleStyleSheet = None  # type: ignore[assignment]
    mm = 1  # type: ignore[assignment]
    pdf_canvas = None  # type: ignore[assignment]
    Paragraph = SimpleDocTemplate = Spacer = Table = TableStyle = None  # type: ignore[assignment]


DEFAULT_BASE_URL = "https://schwarzw.pi-asp.de/loga3/"
DEFAULT_START_MONTH = "2024-10"
TIMEZONE = ZoneInfo("Europe/Berlin")
MONTHS_DE = (
    "Januar", "Februar", "März", "April", "Mai", "Juni",
    "Juli", "August", "September", "Oktober", "November", "Dezember",
)
SMART_IDS = {
    "calendar_pdf": "LAGSDKPF",
    "time_protocol": "LAGSDZPG",
}
LOG = logging.getLogger("loga3")


class LogaError(RuntimeError):
    """Eroare explicita a clientului LOGA."""


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest().upper()


def safe_name(value: Any, fallback: str = "document") -> str:
    text = str(value or "").strip()
    text = re.sub(r'[<>:"/\\|?*\x00-\x1f]', "_", text)
    text = re.sub(r"\s+", " ", text)
    text = re.sub(r"_+", "_", text).strip(" ._")
    return text[:180] or fallback


def parse_year_month(value: str) -> tuple[int, int]:
    match = re.fullmatch(r"(20\d{2})-(0[1-9]|1[0-2])", value)
    if not match:
        raise argparse.ArgumentTypeError(
            f"Luna {value!r} trebuie sa aiba forma YYYY-MM (ex. 2024-10)."
        )
    return int(match.group(1)), int(match.group(2))


def month_key(value: tuple[int, int]) -> str:
    return f"{value[0]:04d}-{value[1]:02d}"


def add_months(value: tuple[int, int], delta: int) -> tuple[int, int]:
    index = value[0] * 12 + value[1] - 1 + delta
    return index // 12, index % 12 + 1


def iter_months(
    start: tuple[int, int], end: tuple[int, int]
) -> Iterator[tuple[int, int]]:
    current = start
    while current[0] * 12 + current[1] <= end[0] * 12 + end[1]:
        yield current
        current = add_months(current, 1)


def last_completed_month(today: date | None = None) -> tuple[int, int]:
    current = today or date.today()
    return add_months((current.year, current.month), -1)


def stable_fingerprint(value: Any) -> str:
    packed = json.dumps(value, ensure_ascii=False, sort_keys=True, default=str)
    return hashlib.sha256(packed.encode("utf-8")).hexdigest().upper()


def gwt_escape(value: str) -> str:
    """Escapeaza intrarile din string-table-ul protocolului GWT-RPC."""
    return value.replace("\\", "\\\\").replace("|", "\\!").replace("\x00", "\\0")


@dataclass(frozen=True)
class RuntimeConfig:
    loga_version: str
    login_permutation: str
    login_strong_name: str
    l2_permutation: str
    xsrf_strong_name: str
    data_mining_strong_name: str | None = None
    resolved_at: str | None = None


class RuntimeConfigResolver:
    """Rezolva automat versiunea si tokenurile GWT din JS-ul public LOGA."""

    def __init__(self, session: Session, base_url: str, cache_path: Path, timeout: int):
        self.session = session
        self.base_url = base_url
        self.cache_path = cache_path
        self.timeout = timeout

    def resolve(self, force: bool = False) -> RuntimeConfig:
        if not force:
            cached = self._load_cache()
            if cached is not None:
                LOG.info("Configuratie GWT din cache: build %s", cached.loga_version)
                return cached

        logout = self._get("public/logout")
        version_match = re.search(
            r"(?:/loga3)?/bts/(\d{17})/(?:Login/)?Login\.nocache\.js", logout
        ) or re.search(r"/bts/(\d{17})/", logout)
        if not version_match:
            raise LogaError("Nu am putut determina build-ul LOGA din public/logout.")
        version = version_match.group(1)

        login_bootstrap = self._get(f"bts/{version}/Login/Login.nocache.js")
        login_permutation = self._extract_permutation(login_bootstrap, "Login")
        login_cache = self._get(
            f"bts/{version}/Login/{login_permutation}.cache.js"
        )
        login_match = re.search(
            r"this\.b=a\+['\"]LoginSrv['\"];this\.e=b;this\.d=['\"]"
            r"([A-F0-9]{32})['\"]",
            login_cache,
        )
        if not login_match:
            raise LogaError("Nu am gasit strong-name-ul serviciului LoginSrv.")

        l2_bootstrap = self._get(f"bts/{version}/L2Main/L2Main.nocache.js")
        l2_permutation = self._extract_permutation(l2_bootstrap, "L2Main")
        l2_cache = self._get(
            f"bts/{version}/L2Main/{l2_permutation}.cache.js"
        )
        xsrf_strong = self._extract_xsrf_strong_name(l2_cache)
        data_mining_strong = self._extract_data_mining_strong_name(l2_cache)

        config = RuntimeConfig(
            loga_version=version,
            login_permutation=login_permutation,
            login_strong_name=login_match.group(1),
            l2_permutation=l2_permutation,
            xsrf_strong_name=xsrf_strong,
            data_mining_strong_name=data_mining_strong,
            resolved_at=datetime.now(TIMEZONE).isoformat(timespec="seconds"),
        )
        self.cache_path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.cache_path.with_suffix(".tmp")
        temporary.write_text(
            json.dumps(asdict(config), ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
        temporary.replace(self.cache_path)
        LOG.info("Configuratie GWT rezolvata: build %s", version)
        return config

    def _get(self, relative: str) -> str:
        response = self.session.get(
            urljoin(self.base_url, relative), timeout=self.timeout
        )
        response.raise_for_status()
        return response.text

    def _load_cache(self) -> RuntimeConfig | None:
        if not self.cache_path.exists():
            return None
        try:
            data = json.loads(self.cache_path.read_text(encoding="utf-8"))
            resolved = datetime.fromisoformat(data["resolved_at"])
            if (datetime.now(TIMEZONE) - resolved).total_seconds() > 6 * 3600:
                return None
            return RuntimeConfig(**data)
        except (OSError, ValueError, KeyError, TypeError):
            return None

    @staticmethod
    def _extract_permutation(bootstrap: str, module: str) -> str:
        match = re.search(r"k\(\[Mb,jc\],([A-Za-z]{2})\)", bootstrap)
        if match:
            alias = re.escape(match.group(1))
            permutation = re.search(
                rf"\b{alias}=['\"]([A-F0-9]{{32}})['\"]", bootstrap
            )
            if permutation:
                return permutation.group(1)

        # Fallback tolerant pentru variante de bootstrap GWT compactate diferit.
        candidates = re.findall(r"['\"]([A-F0-9]{32})['\"]", bootstrap)
        if len(set(candidates)) == 1:
            return candidates[0]
        raise LogaError(f"Nu am putut determina permutarea modulului {module}.")

    @staticmethod
    def _extract_xsrf_strong_name(cache: str) -> str:
        if "getNewXsrfToken" not in cache:
            raise LogaError("Metoda getNewXsrfToken nu exista in build-ul curent.")

        # Instanta proxy este creata imediat inainte ca endpoint-ul private/xsrf
        # sa fie atribuit. Exemplu GWT compactat: c=new o6r; ... 'private/xsrf'.
        for endpoint in re.finditer(r"['\"]private/xsrf['\"]", cache):
            window = cache[max(0, endpoint.start() - 2600):endpoint.start()]
            constructors = re.findall(r"\bnew ([A-Za-z_$][\w$]*)", window)
            for constructor in reversed(constructors):
                definition = re.search(
                    rf"function {re.escape(constructor)}\(\)\{{.{{0,1200}}?"
                    r"['\"]([A-F0-9]{32})['\"]",
                    cache,
                )
                if definition:
                    return definition.group(1)

        raise LogaError("Nu am putut determina strong-name-ul XSRF.")

    @staticmethod
    def _extract_data_mining_strong_name(cache: str) -> str | None:
        endpoint = re.search(
            r"\b([A-Za-z_$][\w$]*)=['\"]privateRPC/DataMiningGwtService['\"]",
            cache,
        )
        if not endpoint:
            return None
        alias = re.escape(endpoint.group(1))
        patterns = (
            rf"\.call\(this,[^;]{{0,500}},{alias},['\"]([A-F0-9]{{32}})['\"]",
            rf"{alias},['\"]([A-F0-9]{{32}})['\"]",
        )
        for pattern in patterns:
            match = re.search(pattern, cache)
            if match:
                return match.group(1)
        return None


@dataclass
class TextResponse:
    """Raspuns sintetic pentru un apel privateRPC decriptat (fara HTTP nou)."""

    text: str
    status_code: int = 200
    headers: Mapping[str, str] = field(default_factory=dict)

    @property
    def content(self) -> bytes:
        return self.text.encode("latin1")


class LogaClient:
    def __init__(
        self,
        base_url: str,
        state_dir: Path,
        timeout: int = 60,
        verify_tls: bool = True,
    ) -> None:
        self.base_url = base_url.rstrip("/") + "/"
        self.origin = f"{urlparse(self.base_url).scheme}://{urlparse(self.base_url).netloc}"
        self.timeout = timeout
        self.verify_tls = verify_tls
        self.state_dir = state_dir
        self.session = requests.Session()
        self.session.verify = verify_tls
        self.session.headers.update(
            {
                "User-Agent": (
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                    "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 "
                    "Safari/537.36"
                ),
                "Accept-Language": "de,de-DE;q=0.9,en;q=0.8",
            }
        )
        retries = Retry(
            total=3,
            connect=3,
            read=2,
            backoff_factor=0.6,
            status_forcelist=(429, 502, 503, 504),
            allowed_methods=frozenset({"GET", "HEAD"}),
        )
        self.session.mount("https://", HTTPAdapter(max_retries=retries))
        self.session.cookies.update(
            {
                "LOGIN_LANGUAGE": "de",
                "LOGIN_COUNTRY": "DE",
                "LOGIN_DISPLAY_NAME_COOKIE": "Deutsch%20(Deutschland)",
                "LOGIN_COUNTRY_ICON_COOKIE": "de",
                "LOGIN_LANG_SHORTCUT_COOKIE": "D",
            }
        )
        self.runtime: RuntimeConfig | None = None
        self.xsrf_token: str | None = None

    def authenticate(self, username: str, password: str, force_runtime: bool = False) -> None:
        resolver = RuntimeConfigResolver(
            self.session,
            self.base_url,
            self.state_dir / "runtime.json",
            self.timeout,
        )
        self.runtime = resolver.resolve(force_runtime)
        module_base = urljoin(
            self.base_url, f"bts/{self.runtime.loga_version}/Login/"
        )
        strings = [
            module_base,
            self.runtime.login_strong_name,
            "_",
            "getLoginResponse",
            "a",
            "Z",
            username,
            password,
            "",
        ]
        payload = (
            "7|1|9|" + "|".join(gwt_escape(item) for item in strings)
            + "|1|2|3|4|5|5|5|5|5|6|7|8|9|9|0|"
        )
        login_url = urljoin(
            self.base_url,
            f"bts/{self.runtime.loga_version}/Login/LoginSrv",
        )
        login_headers = self._gwt_headers(
            module_base, self.runtime.login_permutation, referer="public/logout"
        )
        response = self.session.post(
            login_url,
            data=payload.encode("utf-8"),
            headers=login_headers,
            timeout=self.timeout,
        )
        self._raise_for_status(response, "autentificare")
        if "//EX" in response.text[:20]:
            raise LogaError("LOGA a respins autentificarea (raspuns GWT //EX).")
        if not self.session.cookies.get("JSESSIONID"):
            raise LogaError("LoginSrv nu a returnat cookie-ul JSESSIONID.")

        after = self.session.post(
            urljoin(self.base_url, "private/layout?action=afterlogin"),
            data=b"",
            headers={
                "Origin": self.origin,
                "Referer": urljoin(self.base_url, "public/logout"),
                "Content-Type": "application/x-www-form-urlencoded",
            },
            timeout=self.timeout,
            allow_redirects=True,
        )
        self._raise_for_status(after, "afterlogin")

        l2_base = urljoin(
            self.base_url, f"bts/{self.runtime.loga_version}/L2Main/"
        )
        xsrf_payload = (
            f"7|1|4|{gwt_escape(l2_base)}|{self.runtime.xsrf_strong_name}|_"
            "|getNewXsrfToken|1|2|3|4|0|"
        )
        xsrf = self.session.post(
            urljoin(self.base_url, "private/xsrf"),
            data=xsrf_payload.encode("utf-8"),
            headers=self._gwt_headers(
                l2_base,
                self.runtime.l2_permutation,
                referer="private/layout?action=afterlogin",
            ),
            timeout=self.timeout,
        )
        self._raise_for_status(xsrf, "token XSRF")
        token_match = re.search(r'\["3","([A-F0-9]{32})"\]', xsrf.text, re.I)
        if not token_match:
            raise LogaError(
                "Loginul nu a produs un token XSRF valid. Verifica utilizatorul/parola."
            )
        self.xsrf_token = token_match.group(1).upper()
        LOG.info("Autentificare LOGA reusita.")

    def _gwt_headers(self, module_base: str, permutation: str, referer: str) -> dict[str, str]:
        return {
            "Origin": self.origin,
            "Referer": urljoin(self.base_url, referer),
            "Content-Type": "text/x-gwt-rpc; charset=UTF-8",
            "X-GWT-Module-Base": module_base,
            "X-GWT-Permutation": permutation,
            "Accept": "*/*",
        }

    def request(
        self,
        method: str,
        url: str,
        *,
        add_xsrf: bool = True,
        expected: str = "request",
        **kwargs: Any,
    ) -> Response:
        absolute = urljoin(self.base_url, url)
        parsed = urlparse(absolute)
        base = urlparse(self.base_url)
        if (parsed.scheme, parsed.netloc) != (base.scheme, base.netloc):
            raise LogaError(
                f"Reteta incearca sa trimita sesiunea catre alt host: {parsed.netloc}"
            )
        params = dict(kwargs.pop("params", {}) or {})
        if add_xsrf:
            if not self.xsrf_token:
                raise LogaError("Clientul nu este autentificat (lipseste XSRF).")
            params.setdefault("xsrf", self.xsrf_token)
        headers = {
            "Origin": self.origin,
            "Referer": urljoin(self.base_url, "private/layout?action=afterlogin"),
            **dict(kwargs.pop("headers", {}) or {}),
        }
        response = self.session.request(
            method.upper(),
            absolute,
            params=params,
            headers=headers,
            timeout=kwargs.pop("timeout", self.timeout),
            **kwargs,
        )
        self._raise_for_status(response, expected)
        final_path = urlparse(response.url).path.lower()
        if "/public/logout" in final_path:
            raise LogaError(f"Sesiunea a expirat in timpul operatiei: {expected}.")
        return response

    def json_request(self, method: str, url: str, **kwargs: Any) -> Any:
        headers = {"Accept": "application/json", **kwargs.pop("headers", {})}
        if method.upper() == "POST":
            headers.setdefault("Content-Type", "application/json")
        response = self.request(method, url, headers=headers, **kwargs)
        try:
            return response.json()
        except requests.JSONDecodeError as exc:
            preview = re.sub(r"\s+", " ", response.text[:240])
            raise LogaError(f"Raspunsul nu este JSON: {preview}") from exc

    @staticmethod
    def _raise_for_status(response: Response, operation: str) -> None:
        if response.status_code >= 400:
            preview = re.sub(r"\s+", " ", response.text[:300])
            raise LogaError(
                f"HTTP {response.status_code} la {operation}: {preview}"
            )

    def private_rpc(self, service: str, envelope: str, *, mask: str = "LWSPEP") -> str:
        """Trimite un apel ``privateRPC/<service>`` criptat si intoarce envelope-ul decriptat.

        ``envelope`` este textul compus (``7|3|...|_|<metoda>|<args>``); tokenul
        XSRF curent este folosit atat pentru criptare, cat si pentru headere.
        """
        if self.runtime is None or self.xsrf_token is None:
            raise LogaError("Clientul nu este autentificat (lipseste XSRF).")
        try:
            from loga_rpc import decrypt_body, encrypt_body, rpc_headers
        except ImportError as exc:  # pragma: no cover - depends on layout
            raise LogaError(
                "Modulul loga_rpc.py lipseste langa acest script."
            ) from exc

        module_base = urljoin(
            self.base_url, f"bts/{self.runtime.loga_version}/L2Main/"
        )
        headers = {
            "Origin": self.origin,
            "Referer": urljoin(self.base_url, "private/layout?action=afterlogin"),
            **rpc_headers(
                module_base, self.runtime.l2_permutation, self.xsrf_token, mask=mask
            ),
        }
        body = encrypt_body(self.xsrf_token, envelope)
        response = self.session.post(
            urljoin(self.base_url, f"privateRPC/{service}"),
            data=body.encode("ascii"),
            headers=headers,
            timeout=self.timeout,
        )
        self._raise_for_status(response, f"privateRPC {service}")
        return decrypt_body(self.xsrf_token, response.text)


@dataclass(frozen=True)
class StoreResult:
    status: str
    target: Path
    sha256: str


class DownloadStore:
    def __init__(self, root: Path) -> None:
        self.root = root.resolve()
        self.incoming = self.root / ".incoming"
        self.archive = self.root / "archive"
        self.manifest_path = self.root / "manifest.json"
        self.incoming.mkdir(parents=True, exist_ok=True)
        self.archive.mkdir(parents=True, exist_ok=True)
        self.manifest = self._load_manifest()

    def _load_manifest(self) -> dict[str, Any]:
        empty: dict[str, Any] = {"version": 2, "files": {}, "events": []}
        if not self.manifest_path.exists():
            return empty
        try:
            data = json.loads(self.manifest_path.read_text(encoding="utf-8"))
            if not isinstance(data, dict):
                return empty
            data.setdefault("version", 2)
            data.setdefault("files", {})
            data.setdefault("events", [])
            return data
        except (OSError, json.JSONDecodeError):
            LOG.warning("manifest.json este invalid; pornesc un manifest nou.")
            return empty

    def _save(self) -> None:
        temporary = self.manifest_path.with_suffix(".tmp")
        temporary.write_text(
            json.dumps(self.manifest, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
        temporary.replace(self.manifest_path)

    def target(self, relative: Path) -> Path:
        result = (self.root / relative).resolve()
        if result != self.root and self.root not in result.parents:
            raise LogaError("Calea documentului iese din directorul de output.")
        return result

    def can_skip(self, relative: Path, remote_fingerprint: str) -> bool:
        key = relative.as_posix()
        entry = self.manifest["files"].get(key)
        target = self.target(relative)
        if not entry or not target.is_file():
            return False
        if entry.get("remote_fingerprint") != remote_fingerprint:
            return False
        try:
            return entry.get("sha256") == sha256_file(target)
        except OSError:
            return False

    def temporary_path(self, suffix: str = ".bin") -> Path:
        return self.incoming / f"{uuid.uuid4().hex}{suffix}"

    def ingest(
        self,
        downloaded: Path,
        relative: Path,
        metadata: Mapping[str, Any],
        remote_fingerprint: str,
    ) -> StoreResult:
        digest = sha256_file(downloaded)
        target = self.target(relative)
        target.parent.mkdir(parents=True, exist_ok=True)
        status = "new"
        if target.exists():
            old_hash = sha256_file(target)
            if old_hash == digest:
                downloaded.unlink(missing_ok=True)
                status = "unchanged"
                self._record(relative, status, digest, metadata, remote_fingerprint)
                return StoreResult(status, target, digest)
            stamp = datetime.now(TIMEZONE).strftime("%Y%m%d-%H%M%S")
            archived = self.archive / relative.parent / (
                f"{target.stem}__{stamp}__{old_hash[:10]}{target.suffix}"
            )
            archived.parent.mkdir(parents=True, exist_ok=True)
            shutil.move(str(target), str(archived))
            status = "updated"

        duplicate = self._existing_by_hash(digest, exclude=target)
        if duplicate is not None:
            downloaded.unlink(missing_ok=True)
            try:
                os.link(duplicate, target)
            except OSError:
                shutil.copy2(duplicate, target)
            status = "duplicate-reused"
        else:
            shutil.move(str(downloaded), str(target))
        self._record(relative, status, digest, metadata, remote_fingerprint)
        return StoreResult(status, target, digest)

    def _existing_by_hash(self, digest: str, exclude: Path) -> Path | None:
        for key, entry in self.manifest.get("files", {}).items():
            if entry.get("sha256") != digest:
                continue
            candidate = self.target(Path(key))
            if candidate == exclude or not candidate.is_file():
                continue
            try:
                if sha256_file(candidate) == digest:
                    return candidate
            except OSError:
                continue
        return None

    def _record(
        self,
        relative: Path,
        status: str,
        digest: str,
        metadata: Mapping[str, Any],
        remote_fingerprint: str,
    ) -> None:
        now = datetime.now(TIMEZONE).isoformat(timespec="seconds")
        key = relative.as_posix()
        self.manifest["files"][key] = {
            "sha256": digest,
            "remote_fingerprint": remote_fingerprint,
            "updated_at": now,
            "metadata": dict(metadata),
        }
        self.manifest["events"].append(
            {"timestamp": now, "status": status, "path": key, "sha256": digest}
        )
        # Pastreaza istoricul util, fara crestere nelimitata.
        self.manifest["events"] = self.manifest["events"][-5000:]
        self._save()


@dataclass(frozen=True)
class RemoteDocument:
    source: str
    title: str
    relative: Path
    download_url: str
    metadata: dict[str, Any]

    @property
    def fingerprint(self) -> str:
        return stable_fingerprint(self.metadata)


class GeneratedDocuments:
    def __init__(self, client: LogaClient, store: DownloadStore) -> None:
        self.client = client
        self.store = store

    def sync(
        self,
        source: str,
        man: str | None,
        ak: str | None,
        pnr: str | None,
        verify_all: bool = False,
    ) -> dict[str, int]:
        if source == "auto":
            source = "talent" if man and ak and pnr else "dashboard"
        if source == "talent":
            if not (man and ak and pnr):
                raise LogaError("Sursa talent necesita --man, --ak si --pnr.")
            documents = list(self._talent_documents(man, ak, pnr))
        else:
            documents = list(self._dashboard_documents())

        counts = {"new": 0, "updated": 0, "unchanged": 0, "skipped": 0, "errors": 0}
        LOG.info("Documente gasite prin %s: %d", source, len(documents))
        for index, document in enumerate(documents, 1):
            if not verify_all and self.store.can_skip(document.relative, document.fingerprint):
                counts["skipped"] += 1
                LOG.info("[%d/%d] neschimbat (metadata): %s", index, len(documents), document.title)
                continue
            try:
                response = self.client.request(
                    "GET", document.download_url, expected=f"download {document.title}"
                )
                temporary = self._write_download(response, document.title)
                result = self.store.ingest(
                    temporary, document.relative, document.metadata, document.fingerprint
                )
                counts[result.status] = counts.get(result.status, 0) + 1
                LOG.info("[%d/%d] %s: %s", index, len(documents), result.status, result.target)
            except LogaError as exc:
                # Un document indisponibil nu trebuie sa opreasca restul listei.
                counts["errors"] += 1
                LOG.error("[%d/%d] %s: %s", index, len(documents), document.title, exc)
            time.sleep(0.25)
        return counts

    def dashboard_index(self) -> list[dict[str, Any]]:
        data = self.client.json_request(
            "GET",
            "private/api/dashboard/personalCloud/loadFiles",
            params={
                "millis": str(int(time.time() * 1000)),
                "securityId": "LMAWADOK",
                "maskId": "LMADOKMT",
            },
            expected="index documente dashboard",
        )
        return self._find_dicts_with_key(data, "docId")

    def _dashboard_documents(self) -> Iterable[RemoteDocument]:
        seen: set[str] = set()
        for item in self.dashboard_index():
            doc_id = str(item.get("docId", "")).strip()
            if not doc_id or doc_id in seen:
                continue
            seen.add(doc_id)
            title = str(item.get("name") or f"document-{doc_id}")
            extension = str(item.get("extension") or "").lstrip(".")
            if extension and not title.lower().endswith("." + extension.lower()):
                title += "." + extension
            created_month = self._created_month(item) or "unknown-month"
            metadata = {"source": "dashboard", **item}
            # Mai multe documente pot purta acelasi nume in aceeasi luna
            # (ex. trei "Abrechnung AN Standard" pentru luni diferite).
            # docId-ul garanteaza un nume local unic si stabil pentru dedup.
            yield RemoteDocument(
                source="dashboard",
                title=title,
                relative=Path("generated") / created_month / safe_name(f"{doc_id}_{title}"),
                download_url=f"private/document?document-id={quote(doc_id)}",
                metadata=metadata,
            )

    def _talent_documents(self, man: str, ak: str, pnr: str) -> Iterable[RemoteDocument]:
        data = self.client.json_request(
            "GET",
            "private/api/TalentCard/personalAkte/generated",
            params={"man": man, "ak": ak, "pnr": pnr},
            expected="index documente generate TalentCard",
        )
        yield from self._walk_talent(data, Path("generated"))

    def _walk_talent(self, node: Any, parent: Path) -> Iterable[RemoteDocument]:
        if not isinstance(node, Mapping):
            return
        directories = node.get("directories", node.get("directoryData", [])) or []
        files = node.get("files", node.get("filesData", [])) or []
        for file_info in files:
            if not isinstance(file_info, Mapping):
                continue
            mobile = str(file_info.get("mobileUnique", "")).strip()
            file_id = str(file_info.get("id", "")).strip()
            if not mobile or not file_id:
                continue
            title = str(file_info.get("title") or f"document-{file_id}")
            metadata = {"source": "talent", **dict(file_info)}
            yield RemoteDocument(
                source="talent",
                title=title,
                relative=parent / safe_name(title),
                download_url=(
                    "private/api/TalentCard/personalAkte/download/"
                    f"{quote(mobile, safe='')}/{quote(file_id, safe='')}"
                ),
                metadata=metadata,
            )
        for directory in directories:
            if not isinstance(directory, Mapping):
                continue
            title = directory.get("title") or directory.get("name") or "folder"
            yield from self._walk_talent(directory, parent / safe_name(title, "folder"))

    def _write_download(self, response: Response, title: str) -> Path:
        content_type = response.headers.get("Content-Type", "").lower()
        if "text/html" in content_type or response.content.lstrip().startswith(b"<!DOCTYPE html"):
            raise LogaError(
                f"{title}: serverul a returnat HTML in loc de document. "
                "Endpoint-ul poate fi restrictionat pentru acest utilizator."
            )
        suffix = Path(title).suffix or ".bin"
        temporary = self.store.temporary_path(suffix)
        temporary.write_bytes(response.content)
        return temporary

    @staticmethod
    def _find_dicts_with_key(value: Any, key: str) -> list[dict[str, Any]]:
        result: list[dict[str, Any]] = []
        if isinstance(value, Mapping):
            if key in value:
                result.append(dict(value))
            for child in value.values():
                result.extend(GeneratedDocuments._find_dicts_with_key(child, key))
        elif isinstance(value, list):
            for child in value:
                result.extend(GeneratedDocuments._find_dicts_with_key(child, key))
        return result

    @staticmethod
    def _created_month(item: Mapping[str, Any]) -> str | None:
        for key in ("created", "creationDate", "formattedCreationDate"):
            value = item.get(key)
            if isinstance(value, Mapping):
                value = value.get("date") or value.get("formattedDate")
            if isinstance(value, (int, float)):
                try:
                    return datetime.fromtimestamp(value / 1000, TIMEZONE).strftime("%Y-%m")
                except (OSError, ValueError):
                    continue
            if isinstance(value, str):
                iso = re.search(r"(20\d{2})[-/.](0[1-9]|1[0-2])", value)
                if iso:
                    return f"{iso.group(1)}-{iso.group(2)}"
                german = re.search(r"(?:\d{1,2}[.])?(0?[1-9]|1[0-2])[.](20\d{2})", value)
                if german:
                    return f"{german.group(2)}-{int(german.group(1)):02d}"
        return None


class _InvariantCanvas(pdf_canvas.Canvas if pdf_canvas is not None else object):
    """Canvas reproducibil: acelasi JSON produce acelasi SHA-256 al PDF-ului."""

    def __init__(self, *args: Any, **kwargs: Any) -> None:
        kwargs["invariant"] = 1
        super().__init__(*args, **kwargs)


@dataclass(frozen=True)
class CalendarRow:
    day: str
    start: str
    end: str
    title: str
    details: str
    duration: str


class MonthlyDirectExporter:
    """Export lunar numai prin HTTP, fara replay manual si fara browser."""

    DATE_KEYS = (
        "date", "datum", "day", "startDate", "start_date", "fromDate",
        "validFrom", "begin", "beginn", "start", "from", "von",
    )
    END_KEYS = (
        "endDate", "end_date", "toDate", "validTo", "end", "to", "bis",
    )
    TITLE_KEYS = (
        "title", "name", "text", "label", "description", "bezeichnung",
        "shortcut", "kurzzeichen", "type", "eventType", "category",
    )
    START_TIME_KEYS = ("startTime", "fromTime", "timeFrom", "vonZeit", "beginnZeit")
    END_TIME_KEYS = ("endTime", "toTime", "timeTo", "bisZeit", "endeZeit")

    def __init__(self, client: LogaClient, store: DownloadStore) -> None:
        if SimpleDocTemplate is None:
            raise LogaError(
                "Lipseste dependenta 'reportlab'. Ruleaza: "
                "python -m pip install -r requirements.txt"
            )
        self.client = client
        self.store = store

    def run(
        self,
        start: tuple[int, int],
        end: tuple[int, int],
        only_missing: bool = False,
    ) -> dict[str, int]:
        if start[0] * 12 + start[1] > end[0] * 12 + end[1]:
            raise LogaError("Luna de inceput este dupa luna finala.")
        stats: dict[str, int] = {
            "new": 0,
            "updated": 0,
            "unchanged": 0,
            "duplicate-reused": 0,
            "skipped": 0,
        }
        months = list(iter_months(start, end))
        for index, year_month in enumerate(months, 1):
            LOG.info("Luna %d/%d: %s", index, len(months), month_key(year_month))
            results = self._export_month(year_month, only_missing=only_missing)
            for result in results:
                stats[result.status] = stats.get(result.status, 0) + 1
                LOG.info("%s: %s", result.status, result.target)
            time.sleep(0.35)
        return stats

    def _export_month(
        self, year_month: tuple[int, int], *, only_missing: bool
    ) -> list[StoreResult]:
        year, month = year_month
        last_day = calendar.monthrange(year, month)[1]
        start_date = date(year, month, 1)
        end_date = date(year, month, last_day)
        data = self.client.json_request(
            "GET",
            "private/api/dashboard/calendar/loadEventsInRange",
            params={"from": start_date.isoformat(), "to": end_date.isoformat()},
            expected=f"date calendar {month_key(year_month)}",
        )
        data_fingerprint = stable_fingerprint(data)
        base = Path("monthly") / month_key(year_month)
        specs = (
            (base / "calendar-data.json", "raw-json"),
            (base / "kalendarium.pdf", "calendar_pdf"),
            (base / "zeitprotokoll.pdf", "time_protocol"),
        )

        results: list[StoreResult] = []
        rows = self._calendar_rows(data, year_month)
        for relative, action in specs:
            remote_fp = stable_fingerprint(
                {"month": month_key(year_month), "action": action, "data": data_fingerprint}
            )
            target = self.store.target(relative)
            if only_missing and target.is_file():
                results.append(StoreResult("skipped", target, sha256_file(target)))
                continue
            if self.store.can_skip(relative, remote_fp):
                results.append(StoreResult("skipped", target, sha256_file(target)))
                continue

            if action == "raw-json":
                temporary = self.store.temporary_path(".json")
                temporary.write_text(
                    json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True),
                    encoding="utf-8",
                )
            else:
                temporary = self.store.temporary_path(".pdf")
                if action == "calendar_pdf":
                    self._write_calendar_pdf(temporary, year_month, rows, data_fingerprint)
                else:
                    self._write_time_protocol_pdf(
                        temporary, year_month, rows, data_fingerprint
                    )
            metadata = {
                "source": "dashboard-calendar-api",
                "action": action,
                "month": month_key(year_month),
                "data_sha256": data_fingerprint,
                "event_rows": len(rows),
            }
            results.append(
                self.store.ingest(temporary, relative, metadata, remote_fp)
            )
        return results

    @classmethod
    def _calendar_rows(
        cls, data: Any, year_month: tuple[int, int]
    ) -> list[CalendarRow]:
        candidates: list[Mapping[str, Any]] = []

        def walk(value: Any) -> None:
            if isinstance(value, Mapping):
                lowered = {str(key).lower(): key for key in value}
                has_date = any(key.lower() in lowered for key in cls.DATE_KEYS)
                has_label = any(key.lower() in lowered for key in cls.TITLE_KEYS)
                has_time = any(
                    key.lower() in lowered
                    for key in cls.START_TIME_KEYS + cls.END_TIME_KEYS
                )
                if has_date and (has_label or has_time):
                    candidates.append(value)
                for child in value.values():
                    walk(child)
            elif isinstance(value, list):
                for child in value:
                    walk(child)

        walk(data)
        seen: set[str] = set()
        rows: list[CalendarRow] = []
        for item in candidates:
            signature = stable_fingerprint(item)
            if signature in seen:
                continue
            seen.add(signature)
            date_value = cls._pick(item, cls.DATE_KEYS)
            parsed_date = cls._date_from_value(date_value)
            if parsed_date and (parsed_date.year, parsed_date.month) != year_month:
                continue
            day = parsed_date.isoformat() if parsed_date else cls._scalar(date_value)
            start_raw = cls._pick(item, cls.START_TIME_KEYS)
            end_raw = cls._pick(item, cls.END_TIME_KEYS)
            if start_raw is None:
                start_raw = cls._pick(item, ("start", "from", "begin", "beginn"))
            if end_raw is None:
                end_raw = cls._pick(item, cls.END_KEYS)
            start = cls._time_from_value(start_raw)
            end = cls._time_from_value(end_raw)
            title = cls._scalar(cls._pick(item, cls.TITLE_KEYS)) or "Ereignis"
            details = cls._details(item)
            rows.append(
                CalendarRow(
                    day=day or month_key(year_month),
                    start=start,
                    end=end,
                    title=title,
                    details=details,
                    duration=cls._duration(start, end),
                )
            )
        rows.sort(key=lambda row: (row.day, row.start, row.end, row.title, row.details))
        return rows

    @staticmethod
    def _pick(item: Mapping[str, Any], keys: Iterable[str]) -> Any:
        lowered = {str(key).lower(): value for key, value in item.items()}
        for key in keys:
            if key.lower() in lowered and lowered[key.lower()] not in (None, ""):
                return lowered[key.lower()]
        return None

    @staticmethod
    def _scalar(value: Any) -> str:
        if value is None:
            return ""
        if isinstance(value, bool):
            return "da" if value else "nu"
        if isinstance(value, (str, int, float)):
            return re.sub(r"\s+", " ", str(value)).strip()[:260]
        if isinstance(value, Mapping):
            for key in ("formattedDate", "date", "value", "name", "text", "title"):
                if key in value:
                    return MonthlyDirectExporter._scalar(value[key])
        return ""

    @staticmethod
    def _date_from_value(value: Any) -> date | None:
        if isinstance(value, Mapping):
            year = value.get("year")
            month = value.get("month") or value.get("monthValue")
            day = value.get("day") or value.get("dayOfMonth")
            if all(isinstance(part, (int, float, str)) for part in (year, month, day)):
                try:
                    month_number = int(month)
                    if month_number == 0:
                        month_number = 1
                    return date(int(year), month_number, int(day))
                except (TypeError, ValueError):
                    pass
            for key in ("date", "formattedDate", "value"):
                if key in value:
                    parsed = MonthlyDirectExporter._date_from_value(value[key])
                    if parsed:
                        return parsed
        if isinstance(value, (int, float)):
            try:
                seconds = float(value) / 1000 if abs(float(value)) > 10_000_000_000 else float(value)
                return datetime.fromtimestamp(seconds, TIMEZONE).date()
            except (OSError, OverflowError, ValueError):
                return None
        if isinstance(value, str):
            match = re.search(r"(20\d{2})[-/.](0?[1-9]|1[0-2])[-/.](0?[1-9]|[12]\d|3[01])", value)
            if match:
                try:
                    return date(int(match.group(1)), int(match.group(2)), int(match.group(3)))
                except ValueError:
                    return None
            german = re.search(r"(0?[1-9]|[12]\d|3[01])[.](0?[1-9]|1[0-2])[.](20\d{2})", value)
            if german:
                try:
                    return date(int(german.group(3)), int(german.group(2)), int(german.group(1)))
                except ValueError:
                    return None
        return None

    @staticmethod
    def _time_from_value(value: Any) -> str:
        scalar = MonthlyDirectExporter._scalar(value)
        match = re.search(r"(?:T|\s|^)([01]\d|2[0-3]):([0-5]\d)", scalar)
        return f"{match.group(1)}:{match.group(2)}" if match else ""

    @staticmethod
    def _duration(start: str, end: str) -> str:
        if not start or not end:
            return ""
        start_minutes = int(start[:2]) * 60 + int(start[3:])
        end_minutes = int(end[:2]) * 60 + int(end[3:])
        if end_minutes < start_minutes:
            end_minutes += 24 * 60
        minutes = end_minutes - start_minutes
        return f"{minutes // 60}:{minutes % 60:02d}"

    @classmethod
    def _details(cls, item: Mapping[str, Any]) -> str:
        skip = {key.lower() for key in cls.DATE_KEYS + cls.END_KEYS + cls.TITLE_KEYS + cls.START_TIME_KEYS + cls.END_TIME_KEYS}
        parts: list[str] = []
        for key in sorted(item, key=lambda value: str(value).lower()):
            if str(key).lower() in skip:
                continue
            value = cls._scalar(item[key])
            if value:
                parts.append(f"{key}: {value}")
            if len("; ".join(parts)) > 320:
                break
        return "; ".join(parts)[:340]

    @staticmethod
    def _styles() -> tuple[Any, Any, Any]:
        styles = getSampleStyleSheet()
        title = ParagraphStyle(
            "LogaTitle", parent=styles["Title"], alignment=TA_CENTER,
            fontName="Helvetica-Bold", fontSize=16, leading=19,
        )
        normal = ParagraphStyle(
            "LogaNormal", parent=styles["BodyText"], fontName="Helvetica",
            fontSize=7.5, leading=9.2,
        )
        small = ParagraphStyle(
            "LogaSmall", parent=normal, fontSize=6.5, leading=7.8,
            textColor=colors.HexColor("#555555"),
        )
        return title, normal, small

    @staticmethod
    def _p(value: str, style: Any) -> Any:
        return Paragraph(html.escape(value or "—"), style)

    @classmethod
    def _write_calendar_pdf(
        cls,
        path: Path,
        year_month: tuple[int, int],
        rows: list[CalendarRow],
        data_fingerprint: str,
    ) -> None:
        title_style, normal, small = cls._styles()
        doc = SimpleDocTemplate(
            str(path), pagesize=landscape(A4),
            leftMargin=12 * mm, rightMargin=12 * mm,
            topMargin=10 * mm, bottomMargin=10 * mm,
            title=f"Kalendarium {month_key(year_month)}",
            author="LOGA3 Direct Downloader",
        )
        story: list[Any] = [
            Paragraph(
                f"Kalendarium – {html.escape(MONTHS_DE[year_month[1] - 1])} {year_month[0]}",
                title_style,
            ),
            Spacer(1, 4 * mm),
        ]
        data = [[cls._p(value, normal) for value in ("Data", "De la", "Până la", "Eveniment", "Detalii")]]
        for row in rows:
            data.append([
                cls._p(row.day, normal), cls._p(row.start, normal),
                cls._p(row.end, normal), cls._p(row.title, normal),
                cls._p(row.details, small),
            ])
        if not rows:
            data.append([cls._p("Nu au fost returnate evenimente pentru această lună.", normal), "", "", "", ""])
        table = Table(data, repeatRows=1, colWidths=[27 * mm, 18 * mm, 18 * mm, 62 * mm, 135 * mm])
        table.setStyle(cls._table_style())
        story.extend([
            table,
            Spacer(1, 3 * mm),
            Paragraph(
                "Sursă: API-ul calendarului LOGA · amprentă date: " + data_fingerprint[:16],
                small,
            ),
        ])
        doc.build(story, canvasmaker=_InvariantCanvas)

    @classmethod
    def _write_time_protocol_pdf(
        cls,
        path: Path,
        year_month: tuple[int, int],
        rows: list[CalendarRow],
        data_fingerprint: str,
    ) -> None:
        title_style, normal, small = cls._styles()
        doc = SimpleDocTemplate(
            str(path), pagesize=A4,
            leftMargin=12 * mm, rightMargin=12 * mm,
            topMargin=10 * mm, bottomMargin=10 * mm,
            title=f"Zeitprotokoll {month_key(year_month)}",
            author="LOGA3 Direct Downloader",
        )
        story: list[Any] = [
            Paragraph(
                f"Zeitprotokoll – {html.escape(MONTHS_DE[year_month[1] - 1])} {year_month[0]}",
                title_style,
            ),
            Spacer(1, 4 * mm),
        ]
        data = [[cls._p(value, normal) for value in ("Data", "Start", "Sfârșit", "Durată", "Înregistrare")]]
        for row in rows:
            description = row.title
            if row.details:
                description += " · " + row.details
            data.append([
                cls._p(row.day, normal), cls._p(row.start, normal),
                cls._p(row.end, normal), cls._p(row.duration, normal),
                cls._p(description, small),
            ])
        if not rows:
            data.append([cls._p("Nu au fost returnate înregistrări pentru această lună.", normal), "", "", "", ""])
        table = Table(data, repeatRows=1, colWidths=[27 * mm, 17 * mm, 17 * mm, 17 * mm, 101 * mm])
        table.setStyle(cls._table_style())
        story.extend([
            table,
            Spacer(1, 3 * mm),
            Paragraph(
                "Sursă: API-ul calendarului LOGA · amprentă date: " + data_fingerprint[:16],
                small,
            ),
        ])
        doc.build(story, canvasmaker=_InvariantCanvas)

    @staticmethod
    def _table_style() -> Any:
        return TableStyle([
            ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#51937C")),
            ("TEXTCOLOR", (0, 0), (-1, 0), colors.white),
            ("FONTNAME", (0, 0), (-1, 0), "Helvetica-Bold"),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("GRID", (0, 0), (-1, -1), 0.3, colors.HexColor("#BBBBBB")),
            ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#F4F1EC")]),
            ("LEFTPADDING", (0, 0), (-1, -1), 3),
            ("RIGHTPADDING", (0, 0), (-1, -1), 3),
            ("TOPPADDING", (0, 0), (-1, -1), 3),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ])


TOKEN_RE = re.compile(r"{{([A-Z0-9_]+)}}")


def render_template(value: Any, context: Mapping[str, str]) -> Any:
    if isinstance(value, str):
        def replace(match: re.Match[str]) -> str:
            name = match.group(1)
            if name not in context:
                raise LogaError(f"Placeholder necunoscut in reteta RPC: {name}")
            return context[name]
        return TOKEN_RE.sub(replace, value)
    if isinstance(value, list):
        return [render_template(item, context) for item in value]
    if isinstance(value, Mapping):
        return {key: render_template(item, context) for key, item in value.items()}
    return value


class MonthlyRecipeRunner:
    """Executa secvente HTTP capturate/documentate, fara motor de browser."""

    def __init__(
        self,
        client: LogaClient,
        store: DownloadStore,
        generated: GeneratedDocuments,
        profile_path: Path,
    ) -> None:
        self.client = client
        self.store = store
        self.generated = generated
        try:
            self.profile = json.loads(profile_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            raise LogaError(f"Nu pot citi profilul RPC {profile_path}: {exc}") from exc
        if not isinstance(self.profile.get("actions"), Mapping):
            raise LogaError("Profilul RPC nu contine obiectul 'actions'.")

    def run(
        self,
        start: tuple[int, int],
        end: tuple[int, int],
        only_missing: bool = False,
    ) -> dict[str, int]:
        if start[0] * 12 + start[1] > end[0] * 12 + end[1]:
            raise LogaError("Luna de inceput este dupa luna finala.")
        stats = {"new": 0, "updated": 0, "unchanged": 0, "skipped": 0}
        months = list(iter_months(start, end))
        for month_no, year_month in enumerate(months, 1):
            LOG.info("Luna %d/%d: %s", month_no, len(months), month_key(year_month))
            for action_name in ("calendar_pdf", "time_protocol"):
                action = self.profile["actions"].get(action_name)
                if not isinstance(action, Mapping):
                    LOG.warning(
                        "Profilul nu defineste actiunea %s; se sare.", action_name
                    )
                    continue
                filename = safe_name(
                    action.get("filename")
                    or ("calendar.pdf" if action_name == "calendar_pdf" else "zeitprotokoll.pdf")
                )
                relative = Path("monthly") / month_key(year_month) / filename
                if only_missing and self.store.target(relative).is_file():
                    stats["skipped"] += 1
                    LOG.info("skip existent: %s", relative)
                    continue
                result = self._run_action(action_name, action, year_month, relative)
                stats[result.status] = stats.get(result.status, 0) + 1
                LOG.info("%s: %s", result.status, result.target)
                time.sleep(float(self.profile.get("delay_seconds", 1.0)))
        return stats

    def _run_action(
        self,
        action_name: str,
        action: Mapping[str, Any],
        year_month: tuple[int, int],
        relative: Path,
    ) -> StoreResult:
        context = self._context(year_month, action_name, action)
        requests_spec = action.get("requests")
        if not isinstance(requests_spec, list) or not requests_spec:
            raise LogaError(f"Actiunea {action_name} nu are lista 'requests'.")
        if any("__PASTE" in json.dumps(item) for item in requests_spec):
            raise LogaError(
                f"Actiunea {action_name} este doar exemplu. Completeaza body-ul RPC "
                "din profil conform README.md."
            )

        download_spec = action.get("download", {})
        before_ids: set[str] = set()
        if isinstance(download_spec, Mapping) and download_spec.get("poll_dashboard"):
            before_ids = {
                str(item.get("docId")) for item in self.generated.dashboard_index()
                if item.get("docId") is not None
            }

        last_response: Any = None
        for number, raw_spec in enumerate(requests_spec, 1):
            if not isinstance(raw_spec, Mapping):
                raise LogaError(f"Request invalid in actiunea {action_name}.")
            spec = render_template(raw_spec, context)
            rpc_service = spec.get("rpc_service")
            if rpc_service:
                envelope = str(spec.get("envelope", ""))
                if not envelope:
                    raise LogaError(
                        f"Request-ul {number} din {action_name} nu are 'envelope'."
                    )
                decrypted = self.client.private_rpc(
                    str(rpc_service), envelope, mask=str(spec.get("mask", "LWSPEP"))
                )
                last_response = TextResponse(decrypted)
            else:
                method = str(spec.get("method", "POST")).upper()
                url = str(spec.get("url", ""))
                if not url:
                    raise LogaError(f"Request-ul {number} din {action_name} nu are URL.")
                kwargs: dict[str, Any] = {
                    "headers": spec.get("headers", {}),
                    "params": spec.get("params", {}),
                    "add_xsrf": bool(spec.get("add_xsrf", True)),
                    "expected": f"{action_name}, request {number}",
                }
                if "json" in spec:
                    kwargs["json"] = spec["json"]
                elif "body" in spec:
                    kwargs["data"] = str(spec["body"]).encode("utf-8")
                last_response = self.client.request(method, url, **kwargs)

            # Valorile extrase (ex. instanta mastii, document-id) devin
            # disponibile ca placeholdere in request-urile urmatoare.
            for name, pattern in (spec.get("extract") or {}).items():
                match = re.search(str(pattern), last_response.text)
                if not match:
                    raise LogaError(
                        f"{action_name}: nu am gasit '{name}' in raspunsul "
                        f"request-ului {number}."
                    )
                groups = match.groupdict() or {}
                if "value" in groups:
                    context[name] = groups["value"]
                elif match.groups():
                    context[name] = match.group(1)
                else:
                    context[name] = match.group(0)

        if last_response is None:
            raise LogaError(f"Actiunea {action_name} nu a produs raspuns.")
        response = self._resolve_download(
            last_response, download_spec, context, before_ids, action_name
        )
        if not response.content.startswith(b"%PDF-"):
            content_type = response.headers.get("Content-Type", "")
            raise LogaError(
                f"{action_name}: rezultatul nu este PDF ({content_type or 'tip necunoscut'})."
            )
        temporary = self.store.temporary_path(".pdf")
        temporary.write_bytes(response.content)
        metadata = {
            "source": "monthly-rpc",
            "action": action_name,
            "month": month_key(year_month),
            "profile_version": self.profile.get("version"),
        }
        # Se foloseste hash-ul continutului, deci modificarile cu acelasi nume sunt arhivate.
        remote_fp = stable_fingerprint({**metadata, "sha256": sha256_file(temporary)})
        return self.store.ingest(temporary, relative, metadata, remote_fp)

    def _resolve_download(
        self,
        response: Response,
        raw_download: Any,
        context: dict[str, str],
        before_ids: set[str],
        action_name: str,
    ) -> Response:
        if response.content.startswith(b"%PDF-"):
            return response
        download = raw_download if isinstance(raw_download, Mapping) else {}
        if download.get("poll_dashboard"):
            timeout = int(download.get("timeout_seconds", 90))
            pattern = re.compile(str(download.get("title_regex", ".*")), re.I)
            deadline = time.monotonic() + timeout
            while time.monotonic() < deadline:
                items = self.generated.dashboard_index()
                candidates = [
                    item for item in items
                    if str(item.get("docId", "")) not in before_ids
                    and pattern.search(str(item.get("name", "")))
                ]
                if candidates:
                    doc_id = str(candidates[0]["docId"])
                    return self.client.request(
                        "GET",
                        f"private/document?document-id={quote(doc_id)}",
                        expected=f"PDF generat {action_name}",
                    )
                time.sleep(2)
            raise LogaError(f"{action_name}: documentul nou nu a aparut in dashboard.")

        text = response.text
        url: str | None = None
        if download.get("url_regex"):
            match = re.search(str(download["url_regex"]), text)
            if match:
                url = match.groupdict().get("url") or match.group(1)
        if not url:
            auto = re.search(
                r"((?:https?://[^\s'\"]+)?/?loga3/private/document\?[^\s'\"]*document-id=\d+)",
                text,
            )
            if auto:
                url = auto.group(1)

        document_id: str | None = None
        if download.get("document_id_regex"):
            match = re.search(str(download["document_id_regex"]), text)
            if match:
                document_id = match.groupdict().get("id") or match.group(1)
        if document_id:
            context["DOCUMENT_ID"] = document_id
        if not url and download.get("url"):
            url = str(render_template(download["url"], context))
        if not url and document_id:
            url = f"private/document?document-id={quote(document_id)}"
        if not url:
            preview = re.sub(r"\s+", " ", text[:240])
            raise LogaError(
                f"{action_name}: nu am gasit URL/document-id in raspunsul RPC: {preview}"
            )
        return self.client.request("GET", url, expected=f"PDF generat {action_name}")

    def _context(
        self,
        year_month: tuple[int, int],
        action_name: str,
        action: Mapping[str, Any],
    ) -> dict[str, str]:
        if self.client.runtime is None or self.client.xsrf_token is None:
            raise LogaError("Client neautentificat.")
        year, month = year_month
        last_day = calendar.monthrange(year, month)[1]
        start_date = date(year, month, 1)
        end_date = date(year, month, last_day)
        start_dt = datetime.combine(start_date, datetime_time.min, TIMEZONE)
        end_dt = datetime.combine(end_date, datetime_time.max, TIMEZONE)
        l2_base = urljoin(
            self.client.base_url,
            f"bts/{self.client.runtime.loga_version}/L2Main/",
        )
        return {
            "BASE_URL": self.client.base_url,
            "XSRF": self.client.xsrf_token,
            "LOGA_VERSION": self.client.runtime.loga_version,
            "L2_MODULE_BASE": l2_base,
            "L2_PERMUTATION": self.client.runtime.l2_permutation,
            "DATA_MINING_STRONG_NAME": self.client.runtime.data_mining_strong_name or "",
            "MAN": os.getenv("LOGA_MAN", ""),
            "AK": os.getenv("LOGA_AK", ""),
            "PNR": os.getenv("LOGA_PNR", ""),
            "ACTION": action_name,
            "SMART_ID": str(action.get("smart_id") or SMART_IDS[action_name]),
            "YEAR": str(year),
            "MONTH": f"{month:02d}",
            "MONTH_0": str(month - 1),
            "MONTH_KEY": month_key(year_month),
            "MONTH_NAME_DE": MONTHS_DE[month - 1],
            "START_ISO": start_date.isoformat(),
            "END_ISO": end_date.isoformat(),
            "START_DE": start_date.strftime("%d.%m.%Y"),
            "END_DE": end_date.strftime("%d.%m.%Y"),
            "START_EPOCH_MS": str(int(start_dt.timestamp() * 1000)),
            "END_EPOCH_MS": str(int(end_dt.timestamp() * 1000)),
            "MILLIS": str(int(time.time() * 1000)),
        }


def identity_args(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--man", default=os.getenv("LOGA_MAN"), help="Mandant LOGA")
    parser.add_argument("--ak", default=os.getenv("LOGA_AK"), help="Abrechnungskreis LOGA")
    parser.add_argument("--pnr", default=os.getenv("LOGA_PNR"), help="Personalnummer LOGA")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Descarca date LOGA3 prin HTTP direct, fara browser automatizat."
    )
    parser.add_argument("--base-url", default=DEFAULT_BASE_URL)
    parser.add_argument(
        "--output", type=Path, default=Path(__file__).resolve().parent / "downloads"
    )
    parser.add_argument("--username", default=os.getenv("LOGA_USERNAME"))
    parser.add_argument("--password-env", default="LOGA_PASSWORD")
    parser.add_argument("--timeout", type=int, default=60)
    parser.add_argument("--insecure", action="store_true", help="Dezactiveaza verificarea TLS")
    parser.add_argument("--force-runtime", action="store_true")
    parser.add_argument("--verbose", action="store_true")
    sub = parser.add_subparsers(dest="command", required=False)

    sub.add_parser("login", help="Verifica numai autentificarea directa")

    generated = sub.add_parser("generated", help="Sincronizeaza documentele generate")
    generated.add_argument("--source", choices=("auto", "dashboard", "talent"), default="auto")
    generated.add_argument("--verify-all", action="store_true", help="Redescarca pentru verificare SHA-256")
    identity_args(generated)

    monthly = sub.add_parser("monthly", help="Descarca datele lunare si genereaza ambele PDF-uri")
    monthly.add_argument("--start", type=parse_year_month, default=parse_year_month(DEFAULT_START_MONTH))
    monthly.add_argument("--end", type=parse_year_month, default=None)
    monthly.add_argument("--only-missing", action="store_true")

    all_cmd = sub.add_parser("all", help="Ruleaza documentele generate si rapoartele lunare")
    all_cmd.add_argument("--source", choices=("auto", "dashboard", "talent"), default="auto")
    all_cmd.add_argument("--verify-all", action="store_true")
    identity_args(all_cmd)
    all_cmd.add_argument("--start", type=parse_year_month, default=parse_year_month(DEFAULT_START_MONTH))
    all_cmd.add_argument("--end", type=parse_year_month, default=None)
    all_cmd.add_argument("--only-missing", action="store_true")

    sub.add_parser("self-test", help="Teste locale, fara retea si fara login")

    reports = sub.add_parser(
        "reports", help="Descarca rapoartele proprietare LOGA (Mask) pentru una sau mai multe luni"
    )
    reports.add_argument(
        "--profile",
        type=Path,
        default=None,
        help="Calea catre rpc_profiles.json (implicit: langa script)",
    )
    reports.add_argument(
        "--start", type=parse_year_month, default=parse_year_month(DEFAULT_START_MONTH)
    )
    reports.add_argument("--end", type=parse_year_month, default=None)
    reports.add_argument("--only-missing", action="store_true")
    return parser


def run_self_test() -> None:
    assert parse_year_month("2024-10") == (2024, 10)
    assert add_months((2024, 12), 1) == (2025, 1)
    assert list(iter_months((2024, 11), (2025, 2))) == [
        (2024, 11), (2024, 12), (2025, 1), (2025, 2)
    ]
    assert last_completed_month(date(2026, 8, 9)) == (2026, 7)
    assert safe_name('a<b>:c?.pdf') == "a_b_c_.pdf"
    rendered = render_template("{{YEAR}}-{{MONTH}}", {"YEAR": "2026", "MONTH": "07"})
    assert rendered == "2026-07"
    assert gwt_escape("a|b\\c") == "a\\!b\\\\c"
    sample_rows = MonthlyDirectExporter._calendar_rows(
        {
            "events": [
                {
                    "date": "2026-07-03",
                    "startTime": "07:30",
                    "endTime": "16:00",
                    "title": "OA",
                    "note": "test",
                }
            ]
        },
        (2026, 7),
    )
    assert len(sample_rows) == 1
    assert sample_rows[0].duration == "8:30"
    with tempfile.TemporaryDirectory() as directory:
        store = DownloadStore(Path(directory) / "downloads")
        first = store.temporary_path(".pdf")
        first.write_bytes(b"%PDF-alpha")
        result = store.ingest(first, Path("a.pdf"), {"id": 1}, "remote-1")
        assert result.status == "new"
        assert store.can_skip(Path("a.pdf"), "remote-1")

        same = store.temporary_path(".pdf")
        same.write_bytes(b"%PDF-alpha")
        result = store.ingest(same, Path("a.pdf"), {"id": 1}, "remote-2")
        assert result.status == "unchanged"

        duplicate = store.temporary_path(".pdf")
        duplicate.write_bytes(b"%PDF-alpha")
        result = store.ingest(duplicate, Path("b.pdf"), {"id": 2}, "remote-3")
        assert result.status == "duplicate-reused"

        changed = store.temporary_path(".pdf")
        changed.write_bytes(b"%PDF-beta")
        result = store.ingest(changed, Path("a.pdf"), {"id": 1}, "remote-4")
        assert result.status == "updated"
        assert any((store.archive).rglob("*.pdf"))

        if SimpleDocTemplate is not None:
            pdf_one = Path(directory) / "calendar-one.pdf"
            pdf_two = Path(directory) / "calendar-two.pdf"
            MonthlyDirectExporter._write_calendar_pdf(
                pdf_one, (2026, 7), sample_rows, "A" * 64
            )
            MonthlyDirectExporter._write_calendar_pdf(
                pdf_two, (2026, 7), sample_rows, "A" * 64
            )
            assert pdf_one.read_bytes().startswith(b"%PDF-")
            assert sha256_file(pdf_one) == sha256_file(pdf_two)
    print("Self-test OK")


def credentials(args: argparse.Namespace) -> tuple[str, str]:
    username = args.username or input("Utilizator LOGA: ").strip()
    password = os.getenv(args.password_env)
    if password is None:
        password = getpass.getpass("Parola LOGA: ")
    if not username or not password:
        raise LogaError("Utilizatorul si parola sunt obligatorii.")
    return username, password


def print_stats(label: str, stats: Mapping[str, int]) -> None:
    summary = ", ".join(f"{key}={value}" for key, value in sorted(stats.items()))
    print(f"{label}: {summary}")


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    if args.command is None:
        # Rulare fara argumente: cele doua cai complete, cu intervalele cerute.
        args.command = "all"
        args.source = "dashboard"
        args.verify_all = False
        args.man = args.ak = args.pnr = None
        args.start = parse_year_month(DEFAULT_START_MONTH)
        args.end = None
        args.only_missing = False
    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="[%(asctime)s] %(levelname)s %(message)s",
        datefmt="%H:%M:%S",
    )
    if args.command == "self-test":
        run_self_test()
        return 0

    if requests is None:
        raise LogaError(
            "Lipseste dependenta 'requests'. Ruleaza: "
            "python -m pip install -r requirements.txt"
        )

    output = args.output.resolve()
    store = DownloadStore(output)
    client = LogaClient(
        args.base_url,
        output / ".state",
        timeout=args.timeout,
        verify_tls=not args.insecure,
    )
    username, password = credentials(args)
    client.authenticate(username, password, force_runtime=args.force_runtime)
    # Nu pastram parola mai mult decat este necesar in fluxul aplicatiei.
    password = ""

    if args.command == "login":
        print("Login direct HTTP: OK")
        return 0

    generated = GeneratedDocuments(client, store)
    if args.command in ("generated", "all"):
        stats = generated.sync(
            args.source, args.man, args.ak, args.pnr, verify_all=args.verify_all
        )
        print_stats("Documente generate", stats)

    if args.command in ("monthly", "all"):
        end = args.end or last_completed_month()
        runner = MonthlyDirectExporter(client, store)
        stats = runner.run(args.start, end, only_missing=args.only_missing)
        print_stats("Rapoarte lunare", stats)

    if args.command == "reports":
        profile_path = args.profile or (
            Path(__file__).resolve().parent / "rpc_profiles.json"
        )
        if not profile_path.is_file():
            example = Path(__file__).resolve().parent / "rpc_profiles.example.json"
            raise LogaError(
                f"Profilul RPC nu exista: {profile_path}. Copiaza "
                f"{example.name} in rpc_profiles.json si completeaza campurile "
                "lipsa (vezi README.md)."
            )
        end = args.end or last_completed_month()
        runner = MonthlyRecipeRunner(client, store, generated, profile_path)
        stats = runner.run(args.start, end, only_missing=args.only_missing)
        print_stats("Rapoarte Mask", stats)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        print("\nIntrerupt.", file=sys.stderr)
        raise SystemExit(130)
    except LogaError as exc:
        LOG.error("%s", exc)
        raise SystemExit(2)
