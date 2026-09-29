from functools import lru_cache

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    app_env: str = "production"
    database_url: str = "sqlite:///./data/commerce.db"
    api_bootstrap_key: str = Field(min_length=32)
    api_bootstrap_scopes: str = "products:read,orders:read,orders:write,payments:write,webhooks:manage,admin"
    data_encryption_key: str = Field(min_length=32)
    public_base_url: str = "http://127.0.0.1:8088"
    worker_poll_seconds: float = 2.0
    webhook_timeout_seconds: float = 10.0
    webhook_block_private_networks: bool = True
    default_provider: str = "mock"

    fragment_stel_ssid: str = ""
    fragment_stel_dt: str = ""
    fragment_stel_token: str = ""
    fragment_stel_ton_token: str = ""
    fragment_wallet_seed: str = ""
    fragment_ton_api_key: str = ""
    fragment_wallet_version: str = "V5R1"
    telegram_bot_token: str = ""

    @field_validator("default_provider")
    @classmethod
    def validate_provider(cls, value: str) -> str:
        if value not in {"mock", "fragment", "telegram_bot"}:
            raise ValueError("DEFAULT_PROVIDER must be mock, fragment or telegram_bot")
        return value

    @property
    def bootstrap_scopes(self) -> set[str]:
        return {part.strip() for part in self.api_bootstrap_scopes.split(",") if part.strip()}


@lru_cache
def get_settings() -> Settings:
    return Settings()

