import uuid
from datetime import datetime, timezone

from sqlalchemy import Boolean, DateTime, ForeignKey, Index, Integer, JSON, String, Text, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column, relationship

from .db import Base


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


def uuid4() -> str:
    return str(uuid.uuid4())


class ApiKey(Base):
    __tablename__ = "api_keys"
    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=uuid4)
    name: Mapped[str] = mapped_column(String(100))
    key_prefix: Mapped[str] = mapped_column(String(16), index=True)
    secret_hash: Mapped[str] = mapped_column(String(64), unique=True)
    scopes: Mapped[str] = mapped_column(Text)
    active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    last_used_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)


class Product(Base):
    __tablename__ = "products"
    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=uuid4)
    sku: Mapped[str] = mapped_column(String(80), unique=True, index=True)
    kind: Mapped[str] = mapped_column(String(20), index=True)
    title: Mapped[str] = mapped_column(String(160))
    description: Mapped[str] = mapped_column(Text, default="")
    units: Mapped[int | None] = mapped_column(Integer, nullable=True)
    months: Mapped[int | None] = mapped_column(Integer, nullable=True)
    price_amount: Mapped[int] = mapped_column(Integer)
    price_currency: Mapped[str] = mapped_column(String(12), default="IRR")
    provider: Mapped[str | None] = mapped_column(String(30), nullable=True)
    active: Mapped[bool] = mapped_column(Boolean, default=True, index=True)
    sort_order: Mapped[int] = mapped_column(Integer, default=0)
    provider_data: Mapped[dict] = mapped_column(JSON, default=dict)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow)


class Order(Base):
    __tablename__ = "orders"
    __table_args__ = (
        UniqueConstraint("api_key_id", "idempotency_key", name="uq_order_idempotency"),
        Index("ix_orders_claim", "status", "next_attempt_at", "created_at"),
    )
    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=uuid4)
    api_key_id: Mapped[str] = mapped_column(String(36), index=True)
    idempotency_key: Mapped[str] = mapped_column(String(100))
    client_reference: Mapped[str | None] = mapped_column(String(160), nullable=True, index=True)
    product_id: Mapped[str] = mapped_column(ForeignKey("products.id"))
    product_sku: Mapped[str] = mapped_column(String(80))
    product_title: Mapped[str] = mapped_column(String(160))
    kind: Mapped[str] = mapped_column(String(20))
    units: Mapped[int | None] = mapped_column(Integer, nullable=True)
    months: Mapped[int | None] = mapped_column(Integer, nullable=True)
    recipient: Mapped[str] = mapped_column(String(100), index=True)
    status: Mapped[str] = mapped_column(String(40), default="awaiting_payment", index=True)
    amount: Mapped[int] = mapped_column(Integer)
    currency: Mapped[str] = mapped_column(String(12))
    provider: Mapped[str] = mapped_column(String(30))
    provider_reference: Mapped[str | None] = mapped_column(String(255), nullable=True)
    transaction_hash: Mapped[str | None] = mapped_column(String(255), nullable=True)
    failure_code: Mapped[str | None] = mapped_column(String(80), nullable=True)
    failure_message: Mapped[str | None] = mapped_column(Text, nullable=True)
    attempt_count: Mapped[int] = mapped_column(Integer, default=0)
    next_attempt_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow, onupdate=utcnow)
    paid_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    fulfilled_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    product: Mapped[Product] = relationship()
    payments: Mapped[list["Payment"]] = relationship(back_populates="order")


class Payment(Base):
    __tablename__ = "payments"
    __table_args__ = (UniqueConstraint("api_key_id", "external_reference", name="uq_payment_reference"),)
    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=uuid4)
    order_id: Mapped[str] = mapped_column(ForeignKey("orders.id"), index=True)
    api_key_id: Mapped[str] = mapped_column(String(36), index=True)
    external_reference: Mapped[str] = mapped_column(String(160))
    amount: Mapped[int] = mapped_column(Integer)
    currency: Mapped[str] = mapped_column(String(12))
    method: Mapped[str] = mapped_column(String(40), default="client_wallet")
    status: Mapped[str] = mapped_column(String(30), default="confirmed")
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    order: Mapped[Order] = relationship(back_populates="payments")


class ProviderAttempt(Base):
    __tablename__ = "provider_attempts"
    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=uuid4)
    order_id: Mapped[str] = mapped_column(ForeignKey("orders.id"), index=True)
    provider: Mapped[str] = mapped_column(String(30))
    attempt_number: Mapped[int] = mapped_column(Integer)
    status: Mapped[str] = mapped_column(String(30))
    response_data: Mapped[dict] = mapped_column(JSON, default=dict)
    error_code: Mapped[str | None] = mapped_column(String(80), nullable=True)
    error_message: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)
    finished_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)


class WebhookEndpoint(Base):
    __tablename__ = "webhook_endpoints"
    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=uuid4)
    api_key_id: Mapped[str] = mapped_column(String(36), index=True)
    url: Mapped[str] = mapped_column(Text)
    secret: Mapped[str] = mapped_column(String(255))
    events: Mapped[str] = mapped_column(Text, default="order.updated")
    active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)


class OutboxEvent(Base):
    __tablename__ = "outbox_events"
    __table_args__ = (Index("ix_outbox_pending", "delivered_at", "next_attempt_at"),)
    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=uuid4)
    api_key_id: Mapped[str] = mapped_column(String(36), index=True)
    event_type: Mapped[str] = mapped_column(String(80))
    payload: Mapped[dict] = mapped_column(JSON)
    attempts: Mapped[int] = mapped_column(Integer, default=0)
    next_attempt_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    delivered_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)


class WebhookDelivery(Base):
    __tablename__ = "webhook_deliveries"
    id: Mapped[str] = mapped_column(String(36), primary_key=True, default=uuid4)
    event_id: Mapped[str] = mapped_column(ForeignKey("outbox_events.id"), index=True)
    endpoint_id: Mapped[str] = mapped_column(ForeignKey("webhook_endpoints.id"), index=True)
    status_code: Mapped[int | None] = mapped_column(Integer, nullable=True)
    succeeded: Mapped[bool] = mapped_column(Boolean, default=False)
    error_message: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), default=utcnow)

