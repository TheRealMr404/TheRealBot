#!/usr/bin/env python3
import asyncio
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def now() -> str:
    return datetime.now(timezone.utc).isoformat()


def write_state(output_path: Path, data: dict[str, Any]) -> None:
    temporary = output_path.with_name(f"{output_path.name}.{os.getpid()}.tmp")
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump(data, handle, ensure_ascii=False, separators=(",", ":"))
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, output_path)
        os.chmod(output_path, 0o600)
    finally:
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass


async def authenticate(input_path: Path, output_path: Path) -> None:
    cancel_path = output_path.with_name(f"{output_path.name}.cancel")
    try:
        raw = input_path.read_text(encoding="utf-8")
        payload = json.loads(raw)
    finally:
        try:
            input_path.unlink()
        except FileNotFoundError:
            pass

    seed = str(payload.get("seed", "")).strip()
    wallet_version = str(payload.get("wallet_version", "V5R1")).strip().upper()
    if len(seed.split()) not in (12, 18, 24):
        write_state(output_path, {"status": "error", "code": "INVALID_WALLET", "completed_at": now()})
        return
    if wallet_version not in ("V4R2", "V5R1"):
        write_state(output_path, {"status": "error", "code": "INVALID_WALLET_VERSION", "completed_at": now()})
        return

    try:
        from FragmentAPI import FragmentClient
    except Exception:
        write_state(output_path, {"status": "error", "code": "RUNTIME_MISSING", "completed_at": now()})
        return

    write_state(output_path, {"status": "starting", "started_at": now()})

    def on_status(status: str, value: Any) -> None:
        if cancel_path.exists():
            raise asyncio.CancelledError
        if status == "qr_link" and isinstance(value, str) and value.startswith("https://t.me/oauth?"):
            write_state(output_path, {"status": "waiting", "login_url": value, "updated_at": now()})
        elif status in ("refresh", "consumed", "confirmed"):
            current: dict[str, Any] = {"status": status, "updated_at": now()}
            if status == "refresh" and isinstance(value, str):
                current["login_url"] = f"https://t.me/oauth?startapp={value}"
            write_state(output_path, current)

    try:
        cookies = await asyncio.wait_for(
            FragmentClient.authenticate(
                seed=seed,
                wallet_version=wallet_version,
                phone=None,
                print_qr=False,
                on_status=on_status,
                timeout=30,
            ),
            timeout=300,
        )
        allowed = {
            key: str(cookies[key])
            for key in ("stel_ssid", "stel_dt", "stel_token", "stel_ton_token")
            if cookies.get(key)
        }
        if cancel_path.exists():
            raise asyncio.CancelledError
        if not allowed.get("stel_ssid") or not allowed.get("stel_token"):
            write_state(output_path, {"status": "error", "code": "INCOMPLETE_COOKIES", "completed_at": now()})
            return
        write_state(output_path, {"status": "success", "cookies": allowed, "completed_at": now()})
        for _ in range(180):
            await asyncio.sleep(1)
            if not output_path.exists() or cancel_path.exists():
                return
        try:
            saved = json.loads(output_path.read_text(encoding="utf-8"))
            if saved.get("status") == "success":
                output_path.unlink()
        except (FileNotFoundError, json.JSONDecodeError):
            pass
    except asyncio.TimeoutError:
        write_state(output_path, {"status": "error", "code": "LOGIN_TIMEOUT", "completed_at": now()})
    except asyncio.CancelledError:
        return
    except Exception as exc:
        message = str(exc)
        code = "NETWORK_ERROR" if any(
            marker in message.lower() for marker in ("timeout", "network", "connect", "resolve", "http")
        ) else "AUTH_FAILED"
        write_state(output_path, {"status": "error", "code": code, "completed_at": now()})
    finally:
        try:
            cancel_path.unlink()
        except FileNotFoundError:
            pass


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit(2)
    input_path = Path(sys.argv[1]).resolve()
    output_path = Path(sys.argv[2]).resolve()
    if input_path.parent != output_path.parent:
        raise SystemExit(2)
    cancel_path = output_path.with_name(f"{output_path.name}.cancel")
    try:
        asyncio.run(authenticate(input_path, output_path))
    except Exception:
        if cancel_path.exists():
            try:
                cancel_path.unlink()
            except FileNotFoundError:
                pass
            return
        try:
            write_state(output_path, {"status": "error", "code": "AUTH_FAILED", "completed_at": now()})
        except Exception:
            pass
        raise SystemExit(1)


if __name__ == "__main__":
    main()
