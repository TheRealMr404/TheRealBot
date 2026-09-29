from typing import Any

from sqlalchemy.orm import Session

from .config import Settings, get_settings
from .crypto import decrypt_secret, encrypt_secret
from .db import SessionLocal
from .models import ProviderConfig


CONFIG_FIELDS = {
    "fragment_stel_ssid": True,
    "fragment_stel_dt": True,
    "fragment_stel_token": True,
    "fragment_stel_ton_token": True,
    "fragment_wallet_seed": True,
    "fragment_ton_api_key": True,
    "fragment_wallet_version": False,
    "fragment_show_sender": False,
    "fragment_low_balance_ton": False,
}


def set_runtime_value(db: Session, key: str, value: Any) -> None:
    if key not in CONFIG_FIELDS:
        raise ValueError("Unsupported provider setting")
    serialized = str(value).lower() if isinstance(value, bool) else str(value)
    row = db.get(ProviderConfig, key)
    if row is None:
        row = ProviderConfig(key=key, encrypted_value="", is_secret=CONFIG_FIELDS[key])
        db.add(row)
    row.encrypted_value = encrypt_secret(serialized)
    row.is_secret = CONFIG_FIELDS[key]


def clear_runtime_values(db: Session, keys: list[str]) -> None:
    for key in keys:
        # Store an encrypted empty override so legacy environment values cannot
        # silently reactivate a session after the admin deletes it.
        set_runtime_value(db, key, "")


def get_effective_settings(db: Session | None = None) -> Settings:
    own_session = db is None
    db = db or SessionLocal()
    try:
        updates: dict[str, Any] = {}
        for key in CONFIG_FIELDS:
            row = db.get(ProviderConfig, key)
            if row is None:
                continue
            raw = decrypt_secret(row.encrypted_value)
            if key == "fragment_show_sender":
                updates[key] = raw.lower() in {"1", "true", "yes", "on"}
            elif key == "fragment_low_balance_ton":
                updates[key] = float(raw)
            else:
                updates[key] = raw
        return get_settings().model_copy(update=updates)
    finally:
        if own_session:
            db.close()

