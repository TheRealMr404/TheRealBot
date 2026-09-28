#!/usr/bin/env python3
"""Create and finalize one isolated Fragment Telegram OAuth session."""

from __future__ import annotations

import asyncio
import html
import json
import os
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.parse import unquote


JOB_LIFETIME_SECONDS = 330
CONFIRMED_LIFETIME_SECONDS = 75
SUCCESS_RETENTION_SECONDS = 240
LOGIN_URL_PATTERN = re.compile(r"^https://t\.me/oauth\?startapp=[A-Za-z0-9_-]{8,512}$")
QTOKEN_PATTERN = re.compile(r"^[A-Za-z0-9_-]{8,512}$")
AUTH_RESULT_PATTERN = re.compile(r"^[A-Za-z0-9+/_=-]{8,16384}$")


class OAuthResultMissingError(RuntimeError):
    pass


class FragmentSessionError(RuntimeError):
    pass


def now() -> str:
    return datetime.now(timezone.utc).isoformat()


def write_state(output_path: Path, status: str, **values: Any) -> None:
    payload: dict[str, Any] = {
        "version": 3,
        "status": status,
        "updated_at": now(),
        **values,
    }
    temporary = output_path.with_name(f"{output_path.name}.{os.getpid()}.tmp")
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, ensure_ascii=False, separators=(",", ":"))
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, output_path)
        os.chmod(output_path, 0o600)
    finally:
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass


def safe_login_url(value: Any) -> str | None:
    if not isinstance(value, str):
        return None
    value = value.strip()
    return value if LOGIN_URL_PATTERN.fullmatch(value) else None


def normalize_auth_result(value: Any) -> str | None:
    if not isinstance(value, str):
        return None
    candidate = html.unescape(value.strip().strip("\"'"))
    for _ in range(3):
        decoded = unquote(candidate)
        if decoded == candidate:
            break
        candidate = html.unescape(decoded.strip().strip("\"'"))
    return candidate if AUTH_RESULT_PATTERN.fullmatch(candidate) else None


def extract_auth_result(value: Any) -> str | None:
    if not isinstance(value, str) or not value:
        return None
    if len(value) > 2_000_000:
        value = value[:2_000_000]
    patterns = (
        r"(?:^|[#?&])tgAuthResult=([^&#\s\"'<>]+)",
        r"tgAuthResult[\"':\s=]+[\"']([^\"']+)[\"']",
        r"auth_result[\"':\s=]+[\"']([^\"']+)[\"']",
    )
    for pattern in patterns:
        match = re.search(pattern, value, flags=re.IGNORECASE)
        if match:
            result = normalize_auth_result(match.group(1))
            if result:
                return result
    return None


def extract_auth_result_from_object(value: Any) -> str | None:
    if isinstance(value, dict):
        for key in ("tgAuthResult", "auth_result", "auth"):
            result = normalize_auth_result(value.get(key))
            if result:
                return result
        for key in ("result_url", "url", "redirect_url", "location"):
            result = extract_auth_result(value.get(key))
            if result:
                return result
        for nested in value.values():
            result = extract_auth_result_from_object(nested)
            if result:
                return result
    elif isinstance(value, (list, tuple)):
        for nested in value:
            result = extract_auth_result_from_object(nested)
            if result:
                return result
    return None


def response_header(response: Any, name: str) -> str:
    try:
        return str(response.headers.get(name, "") or "")
    except Exception:
        return ""


def extract_auth_result_from_response(response: Any) -> str | None:
    result = extract_auth_result(response_header(response, "location"))
    if result:
        return result
    try:
        payload = response.json()
    except Exception:
        payload = None
    result = extract_auth_result_from_object(payload)
    if result:
        return result
    try:
        body = str(response.text or "")
    except Exception:
        body = ""
    return extract_auth_result(body)


def read_oauth_result(path: Path) -> str | None:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (FileNotFoundError, OSError, json.JSONDecodeError):
        return None
    token = normalize_auth_result(payload.get("token") if isinstance(payload, dict) else None)
    if not token:
        return None
    try:
        path.unlink()
    except FileNotFoundError:
        pass
    return token


def cookie_dict(session: Any, initial: dict[str, str] | None = None) -> dict[str, str]:
    cookies = dict(initial or {})
    try:
        for cookie in session.cookies.jar:
            name = str(getattr(cookie, "name", ""))
            value = str(getattr(cookie, "value", ""))
            if name and value and value != "DELETED":
                cookies[name] = value
    except Exception:
        try:
            for name, value in session.cookies.items():
                if name and value:
                    cookies[str(name)] = str(value)
        except Exception:
            pass
    return cookies


def parse_fragment_hash(page: str) -> str:
    match = re.search(r"ajInit\((.*?)\);", page, flags=re.DOTALL)
    if not match:
        raise FragmentSessionError("Fragment page hash was not found")
    try:
        data = json.loads(match.group(1))
    except json.JSONDecodeError as exc:
        raise FragmentSessionError("Fragment page metadata is invalid") from exc
    api_url = str(data.get("apiUrl", "")) if isinstance(data, dict) else ""
    hash_match = re.search(r"(?:\?|&)hash=([A-Za-z0-9_-]+)", api_url)
    if not hash_match:
        raise FragmentSessionError("Fragment API hash was not found")
    return hash_match.group(1)


def map_error(exc: BaseException) -> str:
    name = exc.__class__.__name__.lower()
    message = str(exc).lower()
    if isinstance(exc, asyncio.TimeoutError):
        return "LOGIN_TIMEOUT"
    if isinstance(exc, OAuthResultMissingError):
        return "OAUTH_RESULT_MISSING"
    if isinstance(exc, FragmentSessionError):
        return "SESSION_FINALIZE_FAILED"
    if any(marker in message for marker in ("timeout", "network", "connect", "resolve", "http")):
        return "NETWORK_ERROR"
    if "mnemonic" in message or "wallet" in name or "wallet" in message:
        return "WALLET_AUTH_FAILED"
    if "qtoken" in message or "oauth" in message or "telegram" in message:
        return "OAUTH_START_FAILED"
    if "fragmentpage" in name or "parse" in name:
        return "PROVIDER_CHANGED"
    return "AUTH_FAILED"


async def finish_telegram_oauth(session: Any, auth_module: Any, oauth_result_path: Path) -> str | None:
    external = read_oauth_result(oauth_result_path)
    if external:
        return external
    base = auth_module.TELEGRAM_OAUTH_BASE
    params = auth_module.TELEGRAM_BASE_PARAMS
    browser_headers = auth_module.BROWSER_HEADERS
    headers = {
        **browser_headers,
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Referer": f"{base}/auth/auth?{params}",
    }
    # A confirmed login sets stel_token on the polling session. Visiting /auth
    # next lets Telegram establish stel_acid before /auth/push returns the
    # final redirect. Keep /auth/auth as a compatibility fallback for the
    # newer OIDC page used by Fragment.
    urls = (
        f"{base}/auth?{params}",
        f"{base}/auth/push?{params}",
        f"{base}/auth/auth?{params}",
        f"{base}/auth/push?{params}",
    )
    for url in urls:
        external = read_oauth_result(oauth_result_path)
        if external:
            return external
        try:
            response = await asyncio.wait_for(
                session.get(url, headers=headers, allow_redirects=False),
                timeout=10,
            )
        except asyncio.CancelledError:
            raise
        except Exception:
            continue
        result = extract_auth_result_from_response(response)
        if result:
            return result
    return read_oauth_result(oauth_result_path)


async def telegram_oauth(
    requests_module: Any,
    auth_module: Any,
    oauth_result_path: Path,
    cancelled: Any,
    on_status: Any,
    timeout: float,
) -> str:
    async with requests_module.AsyncSession(
        timeout=timeout,
        impersonate="chrome120",
        allow_redirects=False,
    ) as session:
        auth_url = (
            f"{auth_module.TELEGRAM_OAUTH_BASE}/auth/auth?"
            f"{auth_module.TELEGRAM_BASE_PARAMS}&quick_auth=new"
        )
        response = await session.get(auth_url, headers=auth_module.BROWSER_HEADERS, allow_redirects=False)
        response.raise_for_status()
        match = re.search(r"setToken\(['\"]([^'\"]+)['\"]\)", str(response.text or ""))
        if not match or not QTOKEN_PATTERN.fullmatch(match.group(1)):
            raise OAuthResultMissingError("Telegram QR token was not returned")

        current_qtoken = match.group(1)
        on_status("qr_link", f"https://t.me/oauth?startapp={current_qtoken}")
        consumed = False
        confirmed_at: float | None = None
        last_completion_attempt = 0.0

        while True:
            if cancelled():
                raise asyncio.CancelledError
            external = read_oauth_result(oauth_result_path)
            if external:
                on_status("confirmed", None)
                return external
            if confirmed_at is not None and time.monotonic() - confirmed_at > CONFIRMED_LIFETIME_SECONDS:
                raise OAuthResultMissingError("Telegram confirmed but no auth result was returned")

            poll_url = (
                f"{auth_module.TELEGRAM_OAUTH_BASE}/auth/login?"
                f"{auth_module.TELEGRAM_BASE_PARAMS}&qtoken={current_qtoken}"
            )
            try:
                poll_response = await asyncio.wait_for(
                    session.post(
                        poll_url,
                        content=b"",
                        headers={
                            **auth_module.BROWSER_HEADERS,
                            "Content-Type": "application/x-www-form-urlencoded",
                            "Origin": auth_module.TELEGRAM_OAUTH_BASE,
                            "X-Requested-With": "XMLHttpRequest",
                        },
                        allow_redirects=False,
                    ),
                    timeout=10,
                )
                direct_result = extract_auth_result_from_response(poll_response)
                if direct_result:
                    on_status("confirmed", None)
                    return direct_result
                data = poll_response.json()
                status = data.get("status") if isinstance(data, dict) else None
            except asyncio.CancelledError:
                raise
            except Exception:
                await asyncio.sleep(1)
                continue

            if status == "refresh":
                refreshed_qtoken = str(data.get("qtoken", ""))
                if QTOKEN_PATTERN.fullmatch(refreshed_qtoken):
                    current_qtoken = refreshed_qtoken
                    on_status("refresh", current_qtoken)
            elif status == "consumed":
                if not consumed:
                    consumed = True
                    on_status("consumed", None)
            elif status == "confirmed":
                if confirmed_at is None:
                    confirmed_at = time.monotonic()
                    on_status("confirmed", None)
                if time.monotonic() - last_completion_attempt >= 2:
                    last_completion_attempt = time.monotonic()
                    result = await finish_telegram_oauth(session, auth_module, oauth_result_path)
                    if result:
                        return result
            elif status in ("cancelled", "expired", "failed"):
                raise OAuthResultMissingError(f"Telegram OAuth status: {status}")

            await asyncio.sleep(0.5 if confirmed_at is not None else 1)


async def fragment_login(
    requests_module: Any,
    auth_module: Any,
    wallet_cookies: dict[str, str],
    auth_result: str,
    timeout: float,
) -> dict[str, str]:
    async with requests_module.AsyncSession(
        cookies=wallet_cookies,
        timeout=timeout,
        impersonate="chrome120",
        allow_redirects=True,
    ) as session:
        page_headers = {
            "User-Agent": auth_module.BROWSER_HEADERS["User-Agent"],
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.9",
        }
        page_response = await session.get("https://fragment.com/", headers=page_headers)
        page_response.raise_for_status()
        api_hash = parse_fragment_hash(str(page_response.text or ""))
        api_headers = {
            **auth_module.BROWSER_HEADERS,
            "Accept": "application/json, text/javascript, */*; q=0.01",
            "Origin": "https://fragment.com",
            "Referer": "https://fragment.com/",
            "X-Aj-Referer": "https://fragment.com/",
            "X-Requested-With": "XMLHttpRequest",
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        }
        login_response = await session.post(
            f"https://fragment.com/api?hash={api_hash}",
            data={"auth": auth_result, "method": "logIn"},
            headers=api_headers,
        )
        login_response.raise_for_status()
        try:
            login_payload = login_response.json()
        except Exception:
            login_payload = None
        if isinstance(login_payload, dict) and login_payload.get("error"):
            raise FragmentSessionError("Fragment rejected the Telegram login result")
        cookies = cookie_dict(session, wallet_cookies)
        if not cookies.get("stel_token"):
            raise FragmentSessionError("Fragment did not issue stel_token")
        return cookies


async def run_authentication(input_path: Path, output_path: Path) -> None:
    cancel_path = output_path.with_name(f"{output_path.name}.cancel")
    oauth_result_path = output_path.with_name(f"{output_path.stem}.oauth.json")
    try:
        raw = input_path.read_text(encoding="utf-8")
        payload = json.loads(raw)
    except (OSError, json.JSONDecodeError):
        write_state(output_path, "error", code="INVALID_JOB_INPUT")
        return
    finally:
        try:
            input_path.unlink()
        except FileNotFoundError:
            pass

    seed = str(payload.get("seed", "")).strip()
    wallet_version = str(payload.get("wallet_version", "V5R1")).strip().upper()
    if len(seed.split()) not in (12, 18, 24):
        write_state(output_path, "error", code="INVALID_WALLET")
        return
    if wallet_version not in ("V4R2", "V5R1"):
        write_state(output_path, "error", code="INVALID_WALLET_VERSION")
        return

    try:
        from curl_cffi import requests
        from FragmentAPI.utils import auth as fragment_auth
    except Exception:
        write_state(output_path, "error", code="RUNTIME_MISSING")
        return

    started = time.monotonic()
    current_login_url: str | None = None
    write_state(output_path, "starting", phase="wallet_proof", started_at=now())

    def cancelled() -> bool:
        return cancel_path.exists()

    def on_status(status: str, value: Any) -> None:
        nonlocal current_login_url
        if cancelled():
            raise asyncio.CancelledError
        if status == "qr_link":
            current_login_url = safe_login_url(value)
            if current_login_url:
                write_state(output_path, "waiting", phase="telegram_confirmation", login_url=current_login_url)
            return
        if status == "refresh":
            refreshed = safe_login_url(f"https://t.me/oauth?startapp={value}")
            if refreshed:
                current_login_url = refreshed
                write_state(output_path, "waiting", phase="telegram_confirmation", login_url=refreshed, refreshed=True)
            return
        if status in ("consumed", "confirmed"):
            state: dict[str, Any] = {
                "phase": "oauth_finalize" if status == "confirmed" else "telegram_confirmation",
            }
            if current_login_url:
                state["login_url"] = current_login_url
            write_state(output_path, status, **state)

    async def authenticate() -> dict[str, str]:
        wallet_cookies = await fragment_auth.auth_ton_proof(
            seed=seed,
            wallet_version=wallet_version,
            timeout=30,
        )
        if wallet_cookies.get("stel_token"):
            return wallet_cookies
        auth_result = await telegram_oauth(
            requests,
            fragment_auth,
            oauth_result_path,
            cancelled,
            on_status,
            30,
        )
        write_state(output_path, "finalizing", phase="fragment_login")
        return await fragment_login(requests, fragment_auth, wallet_cookies, auth_result, 30)

    auth_task = asyncio.create_task(authenticate())
    try:
        while not auth_task.done():
            if cancelled():
                auth_task.cancel()
                raise asyncio.CancelledError
            if time.monotonic() - started >= JOB_LIFETIME_SECONDS:
                auth_task.cancel()
                raise asyncio.TimeoutError
            await asyncio.sleep(0.25)
        cookies = await auth_task
        allowed = {
            key: str(cookies[key]).strip()
            for key in ("stel_ssid", "stel_dt", "stel_token", "stel_ton_token")
            if cookies.get(key)
        }
        if not allowed.get("stel_ssid") or not allowed.get("stel_token"):
            write_state(output_path, "error", code="INCOMPLETE_COOKIES")
            return
        if not allowed.get("stel_dt"):
            allowed["stel_dt"] = "-180"
        if cancelled():
            raise asyncio.CancelledError
        write_state(output_path, "success", cookies=allowed, completed_at=now())
        for _ in range(SUCCESS_RETENTION_SECONDS * 2):
            await asyncio.sleep(0.5)
            if cancelled() or not output_path.exists():
                return
        try:
            saved = json.loads(output_path.read_text(encoding="utf-8"))
            if saved.get("status") == "success":
                output_path.unlink()
        except (FileNotFoundError, json.JSONDecodeError, OSError):
            pass
    except asyncio.CancelledError:
        if not auth_task.done():
            auth_task.cancel()
        try:
            output_path.unlink()
        except FileNotFoundError:
            pass
    except BaseException as exc:
        if not cancelled():
            write_state(output_path, "error", code=map_error(exc))
    finally:
        if not auth_task.done():
            auth_task.cancel()
        try:
            await auth_task
        except BaseException:
            pass
        for path in (cancel_path, oauth_result_path):
            try:
                path.unlink()
            except FileNotFoundError:
                pass


def validate_paths(input_path: Path, output_path: Path) -> None:
    if input_path.parent != output_path.parent:
        raise ValueError("Job paths must share a directory")
    if not input_path.name.endswith(".input.json") or not output_path.name.endswith(".json"):
        raise ValueError("Invalid job paths")
    input_prefix = input_path.name.removesuffix(".input.json")
    output_prefix = output_path.name.removesuffix(".json")
    if input_prefix != output_prefix or not re.fullmatch(r"mirza_fragment_[a-f0-9]{32}", output_prefix):
        raise ValueError("Invalid job identifier")


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit(2)
    input_path = Path(sys.argv[1]).resolve()
    output_path = Path(sys.argv[2]).resolve()
    try:
        validate_paths(input_path, output_path)
        asyncio.run(run_authentication(input_path, output_path))
    except ValueError:
        raise SystemExit(2)
    except BaseException:
        cancel_path = output_path.with_name(f"{output_path.name}.cancel")
        if cancel_path.exists():
            try:
                cancel_path.unlink()
            except FileNotFoundError:
                pass
            return
        try:
            write_state(output_path, "error", code="AUTH_WORKER_FAILED")
        except Exception:
            pass
        raise SystemExit(1)


if __name__ == "__main__":
    main()
