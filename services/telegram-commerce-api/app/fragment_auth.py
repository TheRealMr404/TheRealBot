import asyncio

from .crypto import decrypt_secret
from .db import SessionLocal
from .models import FragmentAuthJob, utcnow
from .runtime_config import get_effective_settings, set_runtime_value


_running_tasks: set[asyncio.Task] = set()


def _update_job(job_id: str, **values) -> None:
    db = SessionLocal()
    try:
        row = db.get(FragmentAuthJob, job_id)
        if row is not None:
            for key, value in values.items():
                setattr(row, key, value)
            row.updated_at = utcnow()
            db.commit()
    finally:
        db.close()


async def _run_auth(job_id: str) -> None:
    db = SessionLocal()
    initial_error = None
    try:
        job = db.get(FragmentAuthJob, job_id)
        if job is None or not job.encrypted_phone:
            return
        phone = decrypt_secret(job.encrypted_phone)
        settings = get_effective_settings(db)
        seed = settings.fragment_wallet_seed
        version = settings.fragment_wallet_version
    except Exception as exc:
        initial_error = str(exc)[:500]
    finally:
        db.close()

    if initial_error is not None:
        _update_job(
            job_id,
            status="failed",
            progress="configuration_error",
            error_message=initial_error,
            encrypted_phone=None,
            completed_at=utcnow(),
        )
        return

    if not seed:
        _update_job(job_id, status="failed", error_message="Wallet seed is not configured", completed_at=utcnow())
        return

    def on_status(name, _payload):
        mapping = {
            "phone_sent": ("waiting_confirmation", "confirmation_sent"),
            "consumed": ("waiting_confirmation", "confirmation_opened"),
            "confirmed": ("finalizing", "confirmed"),
        }
        if name in mapping:
            status, progress = mapping[name]
            _update_job(job_id, status=status, progress=progress)
        else:
            _update_job(job_id, progress=str(name)[:100])

    try:
        from FragmentAPI import FragmentClient

        _update_job(job_id, status="running", progress="starting")
        cookies = await asyncio.wait_for(
            FragmentClient.authenticate(
                seed=seed,
                wallet_version=version,
                phone=phone,
                print_qr=False,
                on_status=on_status,
                timeout=30,
            ),
            timeout=300,
        )
        required = ["stel_ssid", "stel_dt", "stel_token", "stel_ton_token"]
        missing = [key for key in required if not str(cookies.get(key, "")).strip()]
        if missing:
            raise RuntimeError("Fragment did not return: " + ", ".join(missing))
        db = SessionLocal()
        try:
            for key in required:
                set_runtime_value(db, "fragment_" + key, cookies[key])
            job = db.get(FragmentAuthJob, job_id)
            job.status = "succeeded"
            job.progress = "session_saved"
            job.error_message = None
            job.encrypted_phone = None
            job.completed_at = utcnow()
            job.updated_at = utcnow()
            db.commit()
        finally:
            db.close()
    except asyncio.TimeoutError:
        _update_job(
            job_id,
            status="failed",
            progress="timeout",
            error_message="Telegram confirmation timed out",
            encrypted_phone=None,
            completed_at=utcnow(),
        )
    except Exception as exc:
        safe_message = str(exc).replace(phone, "[redacted]").replace(seed, "[redacted]")
        _update_job(
            job_id,
            status="failed",
            error_message=safe_message[:500],
            encrypted_phone=None,
            completed_at=utcnow(),
        )


def launch_auth(job_id: str) -> None:
    task = asyncio.create_task(_run_auth(job_id))
    _running_tasks.add(task)
    task.add_done_callback(_running_tasks.discard)

