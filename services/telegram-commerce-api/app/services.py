import hashlib
import hmac
import ipaddress
import json
import socket
from datetime import datetime, timedelta, timezone
from urllib.parse import urlparse

import httpx
from fastapi import HTTPException, status
from sqlalchemy import and_, or_, select, update
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from .auth import Principal
from .config import get_settings
from .crypto import decrypt_secret
from .db import SessionLocal
from .models import (
    Order,
    OutboxEvent,
    Payment,
    Product,
    ProviderAttempt,
    WebhookDelivery,
    WebhookEndpoint,
    utcnow,
)
from .providers import get_provider
from .providers.base import ProviderDefinitiveError
from .schemas import OrderCreate, PaymentCreate


FINAL_STATES = {"fulfilled", "failed", "canceled", "refunded", "reconciliation_required"}


def order_payload(order: Order) -> dict:
    return {
        "id": order.id,
        "client_reference": order.client_reference,
        "product_id": order.product_id,
        "product_sku": order.product_sku,
        "product_title": order.product_title,
        "kind": order.kind,
        "units": order.units,
        "months": order.months,
        "recipient": order.recipient,
        "status": order.status,
        "amount": order.amount,
        "currency": order.currency,
        "provider": order.provider,
        "provider_reference": order.provider_reference,
        "transaction_hash": order.transaction_hash,
        "failure_code": order.failure_code,
        "failure_message": order.failure_message,
        "attempt_count": order.attempt_count,
        "created_at": order.created_at.isoformat(),
        "updated_at": order.updated_at.isoformat(),
        "paid_at": order.paid_at.isoformat() if order.paid_at else None,
        "fulfilled_at": order.fulfilled_at.isoformat() if order.fulfilled_at else None,
    }


def emit_order_event(db: Session, order: Order) -> None:
    db.add(
        OutboxEvent(
            api_key_id=order.api_key_id,
            event_type="order.updated",
            payload={"type": "order.updated", "order": order_payload(order)},
        )
    )


def add_payment(db: Session, order: Order, principal: Principal, data: PaymentCreate) -> Payment:
    if data.amount != order.amount or data.currency.upper() != order.currency.upper():
        raise HTTPException(status_code=422, detail="Payment amount or currency does not match the order")
    existing = db.scalar(
        select(Payment).where(
            Payment.api_key_id == principal.id, Payment.external_reference == data.external_reference
        )
    )
    if existing:
        if existing.order_id != order.id:
            raise HTTPException(status_code=409, detail="Payment reference is already used by another order")
        return existing
    if order.status not in {"awaiting_payment", "created"}:
        raise HTTPException(status_code=409, detail="Order does not accept a payment in its current state")
    payment = Payment(
        order_id=order.id,
        api_key_id=principal.id,
        external_reference=data.external_reference,
        amount=data.amount,
        currency=data.currency.upper(),
        method=data.method,
    )
    db.add(payment)
    order.status = "queued"
    order.paid_at = utcnow()
    order.updated_at = utcnow()
    emit_order_event(db, order)
    return payment


def create_order(db: Session, principal: Principal, idempotency_key: str, data: OrderCreate) -> Order:
    existing = db.scalar(
        select(Order).where(Order.api_key_id == principal.id, Order.idempotency_key == idempotency_key)
    )
    if existing:
        same_request = (
            existing.product_id == data.product_id
            and existing.recipient == data.recipient
            and existing.client_reference == data.client_reference
        )
        if not same_request:
            raise HTTPException(status_code=409, detail="Idempotency key was used with different data")
        return existing

    product = db.get(Product, data.product_id)
    if product is None or not product.active:
        raise HTTPException(status_code=404, detail="Product not found")
    provider = product.provider or get_settings().default_provider
    order = Order(
        api_key_id=principal.id,
        idempotency_key=idempotency_key,
        client_reference=data.client_reference,
        product_id=product.id,
        product_sku=product.sku,
        product_title=product.title,
        kind=product.kind,
        units=product.units,
        months=product.months,
        recipient=data.recipient,
        amount=product.price_amount,
        currency=product.price_currency.upper(),
        provider=provider,
        status="awaiting_payment",
    )
    db.add(order)
    db.flush()
    emit_order_event(db, order)
    if data.payment:
        add_payment(db, order, principal, data.payment)
    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        existing = db.scalar(
            select(Order).where(Order.api_key_id == principal.id, Order.idempotency_key == idempotency_key)
        )
        if existing:
            return existing
        raise
    db.refresh(order)
    return order


async def process_one_order(order_id: str) -> bool:
    db = SessionLocal()
    try:
        claimed = db.execute(
            update(Order)
            .where(Order.id == order_id, Order.status == "queued")
            .values(status="processing", attempt_count=Order.attempt_count + 1, updated_at=utcnow())
        )
        if claimed.rowcount != 1:
            db.rollback()
            return False
        db.commit()
        order = db.get(Order, order_id)
        attempt = ProviderAttempt(
            order_id=order.id,
            provider=order.provider,
            attempt_number=order.attempt_count,
            status="started",
        )
        db.add(attempt)
        emit_order_event(db, order)
        db.commit()

        try:
            result = await get_provider(order.provider).purchase(order)
        except ProviderDefinitiveError as exc:
            order = db.get(Order, order_id)
            attempt = db.get(ProviderAttempt, attempt.id)
            order.status = "failed"
            order.failure_code = "PROVIDER_REJECTED"
            order.failure_message = str(exc)[:2000]
            order.updated_at = utcnow()
            attempt.status = "failed"
            attempt.error_code = order.failure_code
            attempt.error_message = order.failure_message
            attempt.finished_at = utcnow()
            emit_order_event(db, order)
            db.commit()
            return True
        except Exception as exc:
            order = db.get(Order, order_id)
            attempt = db.get(ProviderAttempt, attempt.id)
            order.status = "reconciliation_required"
            order.failure_code = "PROVIDER_RESULT_UNCERTAIN"
            order.failure_message = str(exc)[:2000]
            order.updated_at = utcnow()
            attempt.status = "uncertain"
            attempt.error_code = order.failure_code
            attempt.error_message = order.failure_message
            attempt.finished_at = utcnow()
            emit_order_event(db, order)
            db.commit()
            return True

        order = db.get(Order, order_id)
        attempt = db.get(ProviderAttempt, attempt.id)
        order.status = "fulfilled"
        order.provider_reference = result.provider_reference
        order.transaction_hash = result.transaction_hash
        order.failure_code = None
        order.failure_message = None
        order.fulfilled_at = utcnow()
        order.updated_at = utcnow()
        attempt.status = "succeeded"
        attempt.response_data = result.data
        attempt.finished_at = utcnow()
        emit_order_event(db, order)
        db.commit()
        return True
    finally:
        db.close()


async def process_next_order() -> bool:
    db = SessionLocal()
    try:
        order_id = db.scalar(
            select(Order.id)
            .where(
                Order.status == "queued",
                or_(Order.next_attempt_at.is_(None), Order.next_attempt_at <= utcnow()),
            )
            .order_by(Order.created_at)
            .limit(1)
        )
    finally:
        db.close()
    return await process_one_order(order_id) if order_id else False


def validate_webhook_url(url: str) -> None:
    parsed = urlparse(url)
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
        raise HTTPException(status_code=422, detail="Webhook URL must be a public HTTPS URL")
    if not get_settings().webhook_block_private_networks:
        return
    try:
        addresses = {item[4][0] for item in socket.getaddrinfo(parsed.hostname, parsed.port or 443)}
    except socket.gaierror as exc:
        raise HTTPException(status_code=422, detail="Webhook hostname cannot be resolved") from exc
    for address in addresses:
        ip = ipaddress.ip_address(address)
        if not ip.is_global:
            raise HTTPException(status_code=422, detail="Webhook URL cannot target a private network")


async def deliver_next_event() -> bool:
    db = SessionLocal()
    try:
        event = db.scalar(
            select(OutboxEvent)
            .where(
                OutboxEvent.delivered_at.is_(None),
                or_(OutboxEvent.next_attempt_at.is_(None), OutboxEvent.next_attempt_at <= utcnow()),
            )
            .order_by(OutboxEvent.created_at)
            .limit(1)
        )
        if not event:
            return False
        endpoints = db.scalars(
            select(WebhookEndpoint).where(
                WebhookEndpoint.api_key_id == event.api_key_id, WebhookEndpoint.active.is_(True)
            )
        ).all()
        body = json.dumps(
            {"id": event.id, "created_at": event.created_at.isoformat(), **event.payload},
            separators=(",", ":"),
            ensure_ascii=False,
        ).encode("utf-8")
        all_ok = True
        async with httpx.AsyncClient(timeout=get_settings().webhook_timeout_seconds) as client:
            for endpoint in endpoints:
                if event.event_type not in set(filter(None, endpoint.events.split(","))):
                    continue
                timestamp = str(int(datetime.now(timezone.utc).timestamp()))
                signature = hmac.new(
                    decrypt_secret(endpoint.secret).encode("utf-8"),
                    timestamp.encode("ascii") + b"." + body,
                    hashlib.sha256,
                ).hexdigest()
                try:
                    validate_webhook_url(endpoint.url)
                    response = await client.post(
                        endpoint.url,
                        content=body,
                        headers={
                            "Content-Type": "application/json",
                            "X-Commerce-Event": event.event_type,
                            "X-Commerce-Event-Id": event.id,
                            "X-Commerce-Timestamp": timestamp,
                            "X-Commerce-Signature": "v1=" + signature,
                        },
                    )
                    ok = 200 <= response.status_code < 300
                    db.add(
                        WebhookDelivery(
                            event_id=event.id,
                            endpoint_id=endpoint.id,
                            status_code=response.status_code,
                            succeeded=ok,
                            error_message=None if ok else response.text[:1000],
                        )
                    )
                    all_ok = all_ok and ok
                except Exception as exc:
                    all_ok = False
                    db.add(
                        WebhookDelivery(
                            event_id=event.id,
                            endpoint_id=endpoint.id,
                            succeeded=False,
                            error_message=str(exc)[:1000],
                        )
                    )
        event.attempts += 1
        if all_ok or not endpoints:
            event.delivered_at = utcnow()
        elif event.attempts >= 12:
            event.delivered_at = utcnow()
        else:
            delay = min(3600, 2 ** min(event.attempts, 10))
            event.next_attempt_at = utcnow() + timedelta(seconds=delay)
        db.commit()
        return True
    finally:
        db.close()

