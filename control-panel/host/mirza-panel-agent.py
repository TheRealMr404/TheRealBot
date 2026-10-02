#!/usr/bin/env python3
"""Restricted host agent for Mirza Control.

The web container can only request the operations explicitly mapped below. It
never receives a shell, the Docker socket, database credentials, or arbitrary
filesystem access.
"""

from __future__ import annotations

import json
import logging
import os
import queue
import re
import shutil
import signal
import socket
import socketserver
import sqlite3
import subprocess
import threading
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


CONFIG_PATH = Path(os.environ.get("MIRZA_PANEL_AGENT_CONFIG", "/etc/mirza-panel-agent.json"))
SLUG_RE = re.compile(r"^[a-z][a-z0-9-]{1,30}$")
JOB_RE = re.compile(r"^[a-f0-9]{32}$")
DOMAIN_RE = re.compile(r"^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$")
USERNAME_RE = re.compile(r"^[A-Za-z0-9_]{5,32}$")
ADMIN_RE = re.compile(r"^-?[0-9]{5,20}$")
TOKEN_RE = re.compile(r"^[0-9]{5,15}:[A-Za-z0-9_-]{20,80}$")
BACKUP_RE = re.compile(r"^[a-z][a-z0-9-]{1,30}_[0-9]{8}_[0-9]{6}\.tar\.gz$")
ALLOWED_OPERATIONS = {"create", "start", "stop", "restart", "update", "backup", "restore", "remove", "schedule"}
STOP_EVENT = threading.Event()
JOB_QUEUE: queue.Queue[str] = queue.Queue()


def load_config() -> dict[str, Any]:
    with CONFIG_PATH.open("r", encoding="utf-8") as handle:
        config = json.load(handle)
    required = ["db_path", "socket_path", "instances_root", "backups_root", "state_root", "manager_path", "bot_source_dir"]
    for key in required:
        if not isinstance(config.get(key), str) or not config[key]:
            raise RuntimeError(f"Missing agent configuration: {key}")
    return config


CONFIG = load_config()
DB_PATH = Path(CONFIG["db_path"])
SOCKET_PATH = Path(CONFIG["socket_path"])
INSTANCES_ROOT = Path(CONFIG["instances_root"])
BACKUPS_ROOT = Path(CONFIG["backups_root"])
STATE_ROOT = Path(CONFIG["state_root"])
JOBS_ROOT = STATE_ROOT / "jobs"
MANAGER = Path(CONFIG["manager_path"])
BOT_SOURCE = Path(CONFIG["bot_source_dir"])


def utc_now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def db_connect() -> sqlite3.Connection:
    connection = sqlite3.connect(DB_PATH, timeout=10)
    connection.row_factory = sqlite3.Row
    connection.execute("PRAGMA foreign_keys=ON")
    connection.execute("PRAGMA busy_timeout=10000")
    return connection


def safe_slug(value: Any) -> str:
    slug = str(value or "")
    if not SLUG_RE.fullmatch(slug):
        raise ValueError("Invalid bot id")
    return slug


def instance_dir(slug: str) -> Path:
    slug = safe_slug(slug)
    path = (INSTANCES_ROOT / slug).resolve()
    if path.parent != INSTANCES_ROOT.resolve():
        raise ValueError("Invalid instance path")
    return path


def backup_dir(slug: str) -> Path:
    slug = safe_slug(slug)
    path = (BACKUPS_ROOT / slug).resolve()
    if path.parent != BACKUPS_ROOT.resolve():
        raise ValueError("Invalid backup path")
    return path


def compose_prefix(slug: str) -> list[str]:
    directory = instance_dir(slug)
    env_file = directory / ".env"
    compose_file = directory / "compose.yml"
    if not env_file.is_file() or not compose_file.is_file():
        raise FileNotFoundError("Bot installation was not found")
    if subprocess.run(["docker", "compose", "version"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
        return ["docker", "compose", "--env-file", str(env_file), "-f", str(compose_file)]
    binary = shutil.which("docker-compose")
    if not binary:
        raise RuntimeError("Docker Compose is unavailable")
    return [binary, "--env-file", str(env_file), "-f", str(compose_file)]


def redact(value: str) -> str:
    value = re.sub(r"\b[0-9]{5,15}:[A-Za-z0-9_-]{20,80}\b", "[REDACTED_TOKEN]", value)
    value = re.sub(r"(?i)(password|secret|token)(\s*[=:]\s*)\S+", r"\1\2[REDACTED]", value)
    return value[-120000:]


def run_command(command: list[str], timeout: int = 1800, cwd: Path | None = None) -> tuple[bool, str]:
    try:
        completed = subprocess.run(
            command,
            cwd=str(cwd) if cwd else None,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            errors="replace",
            timeout=timeout,
            check=False,
            env={**os.environ, "LC_ALL": "C.UTF-8", "LANG": "C.UTF-8"},
        )
        return completed.returncode == 0, redact(completed.stdout or "")
    except subprocess.TimeoutExpired as exc:
        output = (exc.stdout or "") if isinstance(exc.stdout, str) else ""
        return False, redact(output + "\nOPERATION_TIMEOUT")
    except Exception as exc:  # Logged locally, never exposed verbatim to the web client.
        logging.exception("Command execution failed")
        return False, f"AGENT_ERROR:{type(exc).__name__}"


def update_operation(job_id: str, status: str, message: str, started: bool = False) -> None:
    for attempt in range(6):
        try:
            with db_connect() as db:
                if started:
                    db.execute(
                        "UPDATE operations SET status=?,message=?,started_at=? WHERE job_id=?",
                        (status, message, utc_now(), job_id),
                    )
                else:
                    db.execute(
                        "UPDATE operations SET status=?,message=?,finished_at=? WHERE job_id=?",
                        (status, message, utc_now(), job_id),
                    )
            return
        except sqlite3.OperationalError:
            if attempt == 5:
                logging.exception("Could not update operation %s", job_id)
                return
            time.sleep(0.4 * (attempt + 1))


def update_bot(slug: str, status: str, live_status: str | None = None, deleted: bool = False) -> None:
    with db_connect() as db:
        if deleted:
            db.execute(
                "UPDATE bots SET status='deleted',live_status='stopped',deleted_at=?,updated_at=? WHERE slug=?",
                (utc_now(), utc_now(), slug),
            )
        elif live_status is None:
            db.execute("UPDATE bots SET status=?,updated_at=? WHERE slug=?", (status, utc_now(), slug))
        else:
            db.execute(
                "UPDATE bots SET status=?,live_status=?,updated_at=? WHERE slug=?",
                (status, live_status, utc_now(), slug),
            )


def audit(event: str, slug: str, context: dict[str, Any] | None = None) -> None:
    try:
        with db_connect() as db:
            row = db.execute("SELECT id FROM bots WHERE slug=? LIMIT 1", (slug,)).fetchone()
            db.execute(
                "INSERT INTO audit_log (admin_id,event,bot_id,context,ip_address,created_at) VALUES (NULL,?,?,?,?,?)",
                (event, row["id"] if row else None, json.dumps(context or {}, ensure_ascii=False), "local-agent", utc_now()),
            )
    except Exception:
        logging.exception("Could not write audit event")


def job_path(job_id: str) -> Path:
    if not JOB_RE.fullmatch(job_id):
        raise ValueError("Invalid job id")
    return JOBS_ROOT / f"{job_id}.json"


def write_job(job: dict[str, Any]) -> None:
    path = job_path(job["job_id"])
    temporary = path.with_suffix(".tmp")
    with temporary.open("w", encoding="utf-8") as handle:
        json.dump(job, handle, ensure_ascii=False, separators=(",", ":"))
        handle.flush()
        os.fsync(handle.fileno())
    os.chmod(temporary, 0o600)
    os.replace(temporary, path)


def read_job(job_id: str) -> dict[str, Any]:
    with job_path(job_id).open("r", encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict):
        raise ValueError("Invalid job data")
    return data


def bot_expired(slug: str) -> bool:
    with db_connect() as db:
        row = db.execute("SELECT expires_at FROM bots WHERE slug=? AND deleted_at IS NULL", (slug,)).fetchone()
    if not row:
        return True
    try:
        expiry = datetime.fromisoformat(str(row["expires_at"]).replace("Z", "+00:00"))
        return expiry <= datetime.now(timezone.utc)
    except ValueError:
        return True


def manager_command(arguments: list[str], timeout: int = 1800) -> tuple[bool, str]:
    if not MANAGER.is_file() or not os.access(MANAGER, os.X_OK):
        return False, "MANAGER_NOT_AVAILABLE"
    return run_command([str(MANAGER), *arguments], timeout=timeout)


def operation_create(slug: str, payload: dict[str, Any], job_id: str) -> tuple[bool, str]:
    username = str(payload.get("bot_username", ""))
    token = str(payload.get("token", ""))
    admin_id = str(payload.get("admin_id", ""))
    domain = str(payload.get("domain", "")).lower()
    schedule = str(payload.get("backup_schedule", "daily"))
    retention = int(payload.get("backup_retention", 7))
    if not USERNAME_RE.fullmatch(username) or not TOKEN_RE.fullmatch(token):
        return False, "INVALID_TELEGRAM_CREDENTIALS"
    if not ADMIN_RE.fullmatch(admin_id) or not DOMAIN_RE.fullmatch(domain):
        return False, "INVALID_BOT_CONFIGURATION"
    if schedule not in {"daily", "weekly", "off"} or retention < 1 or retention > 60:
        return False, "INVALID_BACKUP_CONFIGURATION"
    if instance_dir(slug).exists():
        return False, "INSTANCE_ALREADY_EXISTS"

    token_file = STATE_ROOT / f"token-{job_id}"
    try:
        token_file.write_text(token, encoding="utf-8")
        os.chmod(token_file, 0o600)
        arguments = [
            "bot-add", "--id", slug, "--name", username, "--token-file", str(token_file),
            "--admin", admin_id, "--domain", domain, "--schedule", schedule,
            "--retention", str(retention), "--yes",
        ]
        if BOT_SOURCE.is_dir() and (BOT_SOURCE / "index.php").is_file() and (BOT_SOURCE / "table.php").is_file():
            arguments.extend(["--source-dir", str(BOT_SOURCE)])
        return manager_command(arguments, timeout=2400)
    finally:
        try:
            token_file.unlink(missing_ok=True)
        except OSError:
            logging.exception("Could not remove temporary token file")


def operation_start(slug: str) -> tuple[bool, str]:
    if bot_expired(slug):
        return False, "SUBSCRIPTION_EXPIRED"
    ok, output = run_command([*compose_prefix(slug), "up", "-d"], timeout=300)
    if ok:
        refresh_ok, refresh_output = manager_command(["gateway-refresh"], timeout=180)
        return refresh_ok, output + "\n" + refresh_output
    return ok, output


def operation_stop(slug: str) -> tuple[bool, str]:
    return run_command([*compose_prefix(slug), "stop"], timeout=180)


def operation_restore(slug: str, payload: dict[str, Any]) -> tuple[bool, str]:
    name = str(payload.get("backup", ""))
    if not BACKUP_RE.fullmatch(name) or not name.startswith(slug + "_"):
        return False, "INVALID_BACKUP_NAME"
    directory = backup_dir(slug)
    archive = (directory / name).resolve()
    if archive.parent != directory or not archive.is_file():
        return False, "BACKUP_NOT_FOUND"
    return manager_command(["bot-restore", "--id", slug, "--backup", str(archive), "--yes"], timeout=2400)


def perform_job(job: dict[str, Any]) -> tuple[bool, str]:
    operation = str(job.get("operation", ""))
    slug = safe_slug(job.get("slug"))
    payload = job.get("payload") if isinstance(job.get("payload"), dict) else {}
    if operation not in ALLOWED_OPERATIONS:
        return False, "OPERATION_NOT_ALLOWED"
    if operation != "create" and not instance_dir(slug).is_dir():
        return False, "INSTANCE_NOT_FOUND"
    if operation in {"start", "restart", "update", "restore"} and bot_expired(slug):
        return False, "SUBSCRIPTION_EXPIRED"

    if operation == "create":
        return operation_create(slug, payload, job["job_id"])
    if operation == "start":
        return operation_start(slug)
    if operation == "stop":
        return operation_stop(slug)
    if operation == "restart":
        return manager_command(["bot-restart", "--id", slug], timeout=360)
    if operation == "update":
        arguments = ["bot-update", "--id", slug]
        if BOT_SOURCE.is_dir():
            arguments.extend(["--source-dir", str(BOT_SOURCE)])
        return manager_command(arguments, timeout=2400)
    if operation == "backup":
        retention = 7
        with db_connect() as db:
            row = db.execute("SELECT backup_retention FROM bots WHERE slug=?", (slug,)).fetchone()
            if row:
                retention = max(1, min(60, int(row["backup_retention"])))
        return manager_command(["bot-backup", "--id", slug, "--retention", str(retention)], timeout=1200)
    if operation == "restore":
        return operation_restore(slug, payload)
    if operation == "remove":
        return manager_command(["bot-remove", "--id", slug, "--yes"], timeout=1800)
    if operation == "schedule":
        schedule = str(payload.get("schedule", "daily"))
        retention = max(1, min(60, int(payload.get("retention", 7))))
        if schedule not in {"daily", "weekly", "off"}:
            return False, "INVALID_SCHEDULE"
        return manager_command(["bot-backup-schedule", "--id", slug, "--schedule", schedule, "--retention", str(retention)], timeout=120)
    return False, "OPERATION_NOT_IMPLEMENTED"


def friendly_result(operation: str, success: bool, output: str) -> str:
    if success:
        return {
            "create": "ربات با موفقیت ساخته و به تلگرام متصل شد.",
            "start": "ربات روشن شد و در حال سرویس‌دهی است.",
            "stop": "ربات با موفقیت متوقف شد.",
            "restart": "راه‌اندازی مجدد با موفقیت انجام شد.",
            "update": "بروزرسانی با بکاپ ایمنی انجام شد.",
            "backup": "نسخه پشتیبان با موفقیت ساخته شد.",
            "restore": "بازیابی نسخه پشتیبان کامل شد.",
            "remove": "ربات پس از ساخت بکاپ نهایی حذف شد.",
            "schedule": "برنامه بکاپ بروزرسانی شد.",
        }.get(operation, "عملیات با موفقیت انجام شد.")
    reasons = {
        "SUBSCRIPTION_EXPIRED": "اعتبار ربات پایان یافته است؛ ابتدا آن را تمدید کنید.",
        "INSTANCE_ALREADY_EXISTS": "شناسه نصب از قبل روی سرور وجود دارد.",
        "INSTANCE_NOT_FOUND": "فایل‌های نصب این ربات روی سرور پیدا نشد.",
        "INVALID_TELEGRAM_CREDENTIALS": "توکن یا نام کاربری تلگرام معتبر نیست.",
        "INVALID_BOT_CONFIGURATION": "دامنه یا شناسه مدیر معتبر نیست.",
        "BACKUP_NOT_FOUND": "نسخه پشتیبان انتخاب‌شده پیدا نشد.",
        "MANAGER_NOT_AVAILABLE": "فرمان مدیریت Mirza روی سرور در دسترس نیست.",
        "OPERATION_TIMEOUT": "عملیات بیش از زمان مجاز طول کشید و متوقف شد.",
    }
    for code, message in reasons.items():
        if code in output:
            return message
    return "عملیات ناموفق بود. جزئیات فنی فقط در گزارش امن سرور ثبت شد."


def worker_loop() -> None:
    while not STOP_EVENT.is_set():
        try:
            job_id = JOB_QUEUE.get(timeout=1)
        except queue.Empty:
            continue
        try:
            job = read_job(job_id)
            job["status"] = "working"
            job["started_at"] = utc_now()
            write_job(job)
            update_operation(job_id, "working", "در حال اجرا روی سرور", started=True)
            success, output = perform_job(job)
            job["status"] = "success" if success else "failed"
            job["finished_at"] = utc_now()
            job["output"] = output
            if job["operation"] == "create":
                job["payload"] = {"credentials_removed": True}
            write_job(job)
            message = friendly_result(str(job["operation"]), success, output)
            update_operation(job_id, job["status"], message)
            slug = str(job["slug"])
            if success:
                status_map = {
                    "create": ("active", "healthy"),
                    "start": ("active", "running"),
                    "stop": ("suspended", "stopped"),
                    "restart": ("active", "running"),
                    "update": ("active", "healthy"),
                    "restore": ("active", "healthy"),
                }
                if job["operation"] == "remove":
                    update_bot(slug, "deleted", deleted=True)
                elif job["operation"] in status_map:
                    update_bot(slug, *status_map[job["operation"]])
            elif job["operation"] == "create":
                update_bot(slug, "error", "stopped")
            audit(f"agent.{job['operation']}.{'success' if success else 'failed'}", slug)
        except Exception:
            logging.exception("Job %s failed inside agent", job_id)
            try:
                failed_job = read_job(job_id)
                failed_job["status"] = "failed"
                failed_job["finished_at"] = utc_now()
                if failed_job.get("operation") == "create":
                    failed_job["payload"] = {"credentials_removed": True}
                write_job(failed_job)
            except Exception:
                logging.exception("Could not sanitize failed job %s", job_id)
            update_operation(job_id, "failed", "اجرای عملیات در Agent ناموفق بود.")
        finally:
            JOB_QUEUE.task_done()


def docker_status(slug: str) -> str:
    completed = subprocess.run(
        ["docker", "inspect", "-f", "{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}", f"mirza-{slug}-app"],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        text=True,
        timeout=8,
        check=False,
    )
    status = completed.stdout.strip().lower()
    return status if status in {"healthy", "unhealthy", "running", "exited", "created", "restarting", "paused", "dead"} else "stopped"


def list_bots() -> list[dict[str, Any]]:
    result: list[dict[str, Any]] = []
    if not INSTANCES_ROOT.is_dir():
        return result
    for env_file in sorted(INSTANCES_ROOT.glob("*/.env")):
        slug = env_file.parent.name
        if not SLUG_RE.fullmatch(slug):
            continue
        values: dict[str, str] = {}
        try:
            for line in env_file.read_text(encoding="utf-8", errors="replace").splitlines():
                key, separator, value = line.partition("=")
                if separator and key in {"BOT_SLUG", "DOMAIN", "BOT_USERNAME"}:
                    values[key] = value
        except OSError:
            continue
        result.append({
            "slug": slug,
            "domain": values.get("DOMAIN", ""),
            "bot_username": values.get("BOT_USERNAME", ""),
            "status": docker_status(slug),
        })
    return result


def human_size(size: int) -> str:
    value = float(size)
    for unit in ("B", "KB", "MB", "GB"):
        if value < 1024 or unit == "GB":
            return f"{value:.1f} {unit}"
        value /= 1024
    return f"{size} B"


def list_backups(slug: str) -> list[dict[str, Any]]:
    directory = backup_dir(slug)
    if not directory.is_dir():
        return []
    result = []
    for path in sorted(directory.glob(f"{slug}_*.tar.gz"), key=lambda item: item.stat().st_mtime, reverse=True)[:100]:
        if not BACKUP_RE.fullmatch(path.name):
            continue
        stat = path.stat()
        result.append({
            "name": path.name,
            "size": stat.st_size,
            "size_human": human_size(stat.st_size),
            "created_at": datetime.fromtimestamp(stat.st_mtime).strftime("%Y/%m/%d %H:%M"),
        })
    return result


def app_logs(slug: str) -> str:
    safe_slug(slug)
    completed = subprocess.run(
        ["docker", "logs", "--tail", "200", f"mirza-{slug}-app"],
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        errors="replace",
        timeout=15,
        check=False,
    )
    return redact(completed.stdout or "")


def expiry_loop() -> None:
    while not STOP_EVENT.wait(30):
        if not DB_PATH.is_file():
            continue
        try:
            now = utc_now()
            with db_connect() as db:
                rows = db.execute(
                    "SELECT id,slug FROM bots WHERE deleted_at IS NULL AND expires_at<=? AND status NOT IN ('expired','deleted','provisioning')",
                    (now,),
                ).fetchall()
            for row in rows:
                slug = safe_slug(row["slug"])
                try:
                    success, output = operation_stop(slug)
                except FileNotFoundError:
                    success, output = True, "INSTANCE_NOT_FOUND_AFTER_EXPIRY"
                if success:
                    update_bot(slug, "expired", "stopped")
                    job_id = f"expiry{int(time.time()):010d}{row['id']:06d}"[:32].ljust(32, "0")
                    with db_connect() as db:
                        db.execute(
                            "INSERT OR IGNORE INTO operations (job_id,bot_id,operation,status,message,created_at,started_at,finished_at) VALUES (?,?,?,?,?,?,?,?)",
                            (job_id, row["id"], "expire", "success", "اعتبار پایان یافت و ربات به‌صورت خودکار متوقف شد.", now, now, now),
                        )
                    audit("agent.expired", slug)
                else:
                    logging.error("Could not stop expired bot %s: %s", slug, output[-1000:])
        except Exception:
            logging.exception("Expiry enforcement failed")


class AgentHandler(socketserver.StreamRequestHandler):
    def handle(self) -> None:
        self.connection.settimeout(8)
        raw = self.rfile.readline(65537)
        if not raw or len(raw) > 65536:
            self.reply(False, "Invalid request")
            return
        try:
            request = json.loads(raw.decode("utf-8"))
            if not isinstance(request, dict):
                raise ValueError("Invalid request")
            action = request.get("action")
            if action == "ping":
                self.reply(True, "Agent is ready", version=1)
                return
            if action == "list":
                self.reply(True, "", bots=list_bots())
                return
            if action == "backups":
                slug = safe_slug(request.get("slug"))
                self.reply(True, "", backups=list_backups(slug))
                return
            if action == "logs":
                slug = safe_slug(request.get("slug"))
                self.reply(True, "", logs=app_logs(slug))
                return
            if action == "enqueue":
                self.enqueue(request)
                return
            self.reply(False, "Operation is not allowed")
        except (ValueError, TypeError, json.JSONDecodeError) as exc:
            self.reply(False, str(exc))
        except Exception:
            logging.exception("Agent request failed")
            self.reply(False, "Agent could not process the request")

    def enqueue(self, request: dict[str, Any]) -> None:
        job_id = str(request.get("job_id", ""))
        operation = str(request.get("operation", ""))
        slug = safe_slug(request.get("slug"))
        payload = request.get("payload") if isinstance(request.get("payload"), dict) else {}
        if not JOB_RE.fullmatch(job_id) or operation not in ALLOWED_OPERATIONS:
            raise ValueError("Invalid job")
        path = job_path(job_id)
        if path.exists():
            self.reply(True, "Job already queued", job_id=job_id)
            return
        job = {
            "job_id": job_id,
            "operation": operation,
            "slug": slug,
            "payload": payload,
            "status": "queued",
            "created_at": utc_now(),
        }
        write_job(job)
        JOB_QUEUE.put(job_id)
        self.reply(True, "Job queued", job_id=job_id)

    def reply(self, ok: bool, message: str, **extra: Any) -> None:
        payload = {"ok": ok, "message": message, **extra}
        encoded = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8") + b"\n"
        self.wfile.write(encoded)


class AgentServer(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True
    allow_reuse_address = True


def restore_pending_jobs() -> None:
    for path in JOBS_ROOT.glob("*.json"):
        try:
            job = json.loads(path.read_text(encoding="utf-8"))
            if job.get("status") in {"queued", "working"} and JOB_RE.fullmatch(str(job.get("job_id", ""))):
                job["status"] = "queued"
                write_job(job)
                JOB_QUEUE.put(job["job_id"])
        except Exception:
            logging.exception("Could not restore queued job from %s", path)


def shutdown_handler(_signum: int, _frame: Any) -> None:
    STOP_EVENT.set()
    try:
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as wake:
            wake.connect(str(SOCKET_PATH))
            wake.sendall(b'{"action":"ping"}\n')
    except OSError:
        pass


def main() -> int:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        handlers=[logging.FileHandler("/var/log/mirza-panel-agent.log"), logging.StreamHandler()],
    )
    for directory, mode in ((STATE_ROOT, 0o700), (JOBS_ROOT, 0o700), (SOCKET_PATH.parent, 0o770)):
        directory.mkdir(parents=True, exist_ok=True)
        os.chmod(directory, mode)
    # The official php:apache image runs www-data with numeric GID 33. Bind
    # mount permissions must use that numeric group, regardless of host names.
    web_gid = 33
    os.chown(SOCKET_PATH.parent, 0, web_gid)
    SOCKET_PATH.unlink(missing_ok=True)
    signal.signal(signal.SIGTERM, shutdown_handler)
    signal.signal(signal.SIGINT, shutdown_handler)
    restore_pending_jobs()
    threading.Thread(target=worker_loop, name="mirza-worker", daemon=True).start()
    threading.Thread(target=expiry_loop, name="mirza-expiry", daemon=True).start()

    with AgentServer(str(SOCKET_PATH), AgentHandler) as server:
        os.chmod(SOCKET_PATH, 0o660)
        os.chown(SOCKET_PATH, 0, web_gid)
        logging.info("Mirza panel agent is ready on %s", SOCKET_PATH)
        while not STOP_EVENT.is_set():
            server.handle_request()
    SOCKET_PATH.unlink(missing_ok=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
