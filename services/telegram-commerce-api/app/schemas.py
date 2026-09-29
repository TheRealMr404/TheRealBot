from datetime import datetime
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, SecretStr, field_validator


ProductKind = Literal["stars", "premium"]
ProviderName = Literal["mock", "fragment", "telegram_bot"]


class ProductBase(BaseModel):
    sku: str = Field(min_length=2, max_length=80, pattern=r"^[a-zA-Z0-9._-]+$")
    kind: ProductKind
    title: str = Field(min_length=2, max_length=160)
    description: str = Field(default="", max_length=2000)
    units: int | None = Field(default=None, ge=50, le=10_000_000)
    months: int | None = None
    price_amount: int = Field(gt=0)
    price_currency: str = Field(default="IRR", min_length=2, max_length=12)
    provider: ProviderName | None = None
    active: bool = True
    sort_order: int = 0
    provider_data: dict = Field(default_factory=dict)

    @field_validator("months")
    @classmethod
    def valid_months(cls, value: int | None) -> int | None:
        if value is not None and value not in {3, 6, 12}:
            raise ValueError("Premium month count must be 3, 6 or 12")
        return value

    def model_post_init(self, __context) -> None:
        if self.kind == "stars" and self.units is None:
            raise ValueError("Stars products require units")
        if self.kind == "premium" and self.months is None:
            raise ValueError("Premium products require months")
        if self.kind == "stars" and self.provider == "telegram_bot":
            raise ValueError("telegram_bot provider supports Premium only")


class ProductCreate(ProductBase):
    pass


class ProductUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=2, max_length=160)
    description: str | None = Field(default=None, max_length=2000)
    price_amount: int | None = Field(default=None, gt=0)
    price_currency: str | None = Field(default=None, min_length=2, max_length=12)
    provider: ProviderName | None = None
    active: bool | None = None
    sort_order: int | None = None
    provider_data: dict | None = None


class ProductOut(ProductBase):
    model_config = ConfigDict(from_attributes=True)
    id: str
    created_at: datetime
    updated_at: datetime


class ProductPublic(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: str
    sku: str
    kind: ProductKind
    title: str
    description: str
    units: int | None
    months: int | None
    price_amount: int
    price_currency: str
    provider: ProviderName | None
    active: bool
    sort_order: int
    created_at: datetime
    updated_at: datetime


class QuoteCreate(BaseModel):
    product_id: str
    recipient: str | None = Field(default=None, max_length=100)


class QuoteOut(BaseModel):
    quote_id: str
    product_id: str
    amount: int
    currency: str
    expires_at: datetime


class PaymentCreate(BaseModel):
    external_reference: str = Field(min_length=1, max_length=160)
    amount: int = Field(gt=0)
    currency: str = Field(min_length=2, max_length=12)
    method: str = Field(default="client_wallet", min_length=2, max_length=40)


class PaymentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: str
    order_id: str
    external_reference: str
    amount: int
    currency: str
    method: str
    status: str
    created_at: datetime


class OrderCreate(BaseModel):
    product_id: str
    recipient: str = Field(min_length=1, max_length=100)
    client_reference: str | None = Field(default=None, max_length=160)
    payment: PaymentCreate | None = None

    @field_validator("recipient")
    @classmethod
    def normalize_recipient(cls, value: str) -> str:
        value = value.strip()
        if value.startswith("https://t.me/"):
            value = value.removeprefix("https://t.me/")
        return value.lstrip("@").strip()


class OrderOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: str
    client_reference: str | None
    product_id: str
    product_sku: str
    product_title: str
    kind: str
    units: int | None
    months: int | None
    recipient: str
    status: str
    amount: int
    currency: str
    provider: str
    provider_reference: str | None
    transaction_hash: str | None
    failure_code: str | None
    failure_message: str | None
    attempt_count: int
    created_at: datetime
    updated_at: datetime
    paid_at: datetime | None
    fulfilled_at: datetime | None


class OrderList(BaseModel):
    items: list[OrderOut]
    next_cursor: str | None = None


class WebhookCreate(BaseModel):
    url: str = Field(min_length=8, max_length=2000)
    secret: str = Field(min_length=32, max_length=255)
    events: list[str] = Field(default_factory=lambda: ["order.updated"])


class WebhookOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: str
    url: str
    events: list[str]
    active: bool
    created_at: datetime


class ApiKeyCreate(BaseModel):
    name: str = Field(min_length=2, max_length=100)
    scopes: list[str]


class ApiKeyCreated(BaseModel):
    id: str
    name: str
    key: str
    prefix: str
    scopes: list[str]


class ProviderConfigUpdate(BaseModel):
    fragment_wallet_seed: SecretStr | None = None
    fragment_ton_api_key: SecretStr | None = None
    fragment_wallet_version: Literal["V4R2", "V5R1"] | None = None
    fragment_show_sender: bool | None = None
    fragment_low_balance_ton: float | None = Field(default=None, ge=0, le=1_000_000)

    @field_validator("fragment_wallet_seed")
    @classmethod
    def validate_seed(cls, value: SecretStr | None) -> SecretStr | None:
        if value is not None and len(value.get_secret_value().strip().split()) not in {12, 18, 24}:
            raise ValueError("Wallet seed must contain 12, 18 or 24 words")
        return value


class FragmentAuthStart(BaseModel):
    phone: SecretStr

    @field_validator("phone")
    @classmethod
    def validate_phone(cls, value: SecretStr) -> SecretStr:
        phone = value.get_secret_value().strip()
        digits = "".join(character for character in phone if character.isdigit())
        if len(digits) < 8 or len(digits) > 15:
            raise ValueError("Invalid phone number")
        return SecretStr("+" + digits)


class FragmentAuthOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: str
    status: str
    progress: str | None
    error_message: str | None
    created_at: datetime
    updated_at: datetime
    completed_at: datetime | None

