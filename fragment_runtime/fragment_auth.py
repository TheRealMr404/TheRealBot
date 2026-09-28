#!/usr/bin/env python3
"""Run one Fragment wallet + Telegram OAuth login as an isolated job."""

from __future__ import annotations

import asyncio
import json
import os
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


JOB_LIFETIME_SECONDS = 330
SUCCESS_RETENTION_SECONDS = 240
LOGIN_URL_PATTERN = re.compile(r"^https://t\.me/oauth\?startapp=[A-Za-z0-9_-]{8,512}$")


def now() -> str:
    return datetime.now(timezone.utc).isoformat()


def write_state(output_path: Path, status: str, **values: Any) -> None:
    payload: dict[str, Any] = {
        "version": 2,
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


def map_error(exc: BaseException) -> str:
    name = exc.__class__.__name__.lower()
    message = str(exc).lower()
    if isinstance(exc, asyncio.TimeoutError):
        return "LOGIN_TIMEOUT"
    if any(marker in message for marker in ("timeout", "network", "connect", "resolve", "http")):
        return "NETWORK_ERROR"
    if "mnemonic" in message or "wallet" in name or "wallet" in message:
        return "WALLET_AUTH_FAILED"
    if "qtoken" in message or "oauth" in message or "telegram" in message:
        return "OAUTH_START_FAILED"
    if "fragmentpage" in name or "parse" in name:
        return "PROVIDER_CHANGED"
    return "AUTH_FAILED"


async def run_authentication(input_path: Path, output_path: Path) -> None:
    cancel_path = output_path.with_name(f"{output_path.name}.cancel")
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
        from FragmentAPI import FragmentClient
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
                write_state(
                    output_path,
                    "waiting",
                    phase="telegram_confirmation",
                    login_url=current_login_url,
                )
            return

        if status == "refresh":
            refreshed = safe_login_url(f"https://t.me/oauth?startapp={value}")
            if refreshed:
                current_login_url = refreshed
                write_state(
                    output_path,
                    "waiting",
                    phase="telegram_confirmation",
                    login_url=current_login_url,
                    refreshed=True,
                )
            return

        if status in ("consumed", "confirmed"):
            state: dict[str, Any] = {"phase": "session_finalize"}
            if current_login_url:
                state["login_url"] = current_login_url
            write_state(output_path, status, **state)

    auth_task = asyncio.create_task(
        FragmentClient.authenticate(
            seed=seed,
            wallet_version=wallet_version,
            phone=None,
            print_qr=False,
            on_status=on_status,
            timeout=30,
        )
    )

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
        return
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
        try:
            cancel_path.unlink()
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
