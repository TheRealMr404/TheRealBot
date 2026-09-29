import hashlib
import hmac
import secrets
from dataclasses import dataclass
from datetime import datetime, timezone

from fastapi import Depends, HTTPException, Security, status
from fastapi.security import APIKeyHeader
from sqlalchemy import select
from sqlalchemy.orm import Session

from .config import get_settings
from .db import get_db
from .models import ApiKey


api_key_header = APIKeyHeader(name="X-API-Key", auto_error=False)


@dataclass(frozen=True)
class Principal:
    id: str
    name: str
    scopes: frozenset[str]


def hash_api_key(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def generate_api_key() -> str:
    return "mztc_" + secrets.token_urlsafe(36)


def authenticate_api_key(
    value: str | None = Security(api_key_header), db: Session = Depends(get_db)
) -> Principal:
    if not value:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="API key is required")
    settings = get_settings()
    if hmac.compare_digest(value, settings.api_bootstrap_key):
        return Principal("bootstrap", "bootstrap", frozenset(settings.bootstrap_scopes))

    digest = hash_api_key(value)
    row = db.scalar(select(ApiKey).where(ApiKey.secret_hash == digest, ApiKey.active.is_(True)))
    if row is None:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid API key")
    row.last_used_at = datetime.now(timezone.utc)
    db.commit()
    return Principal(row.id, row.name, frozenset(filter(None, row.scopes.split(","))))


def require_scope(scope: str):
    def dependency(principal: Principal = Depends(authenticate_api_key)) -> Principal:
        if scope not in principal.scopes and "admin" not in principal.scopes:
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail=f"Missing scope: {scope}")
        return principal

    return dependency

