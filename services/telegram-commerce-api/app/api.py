from datetime import timedelta
from uuid import uuid4

from fastapi import APIRouter, Depends, Header, HTTPException, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from .auth import Principal, generate_api_key, hash_api_key, require_scope
from .config import get_settings
from .crypto import encrypt_secret
from .db import get_db
from .fragment_auth import launch_auth
from .models import ApiKey, FragmentAuthJob, Order, Payment, Product, WebhookEndpoint, utcnow
from .runtime_config import clear_runtime_values, get_effective_settings, set_runtime_value
from .schemas import (
    ApiKeyCreate,
    ApiKeyCreated,
    FragmentAuthOut,
    FragmentAuthStart,
    OrderCreate,
    OrderList,
    OrderOut,
    PaymentCreate,
    PaymentOut,
    ProductCreate,
    ProductOut,
    ProductPublic,
    ProductUpdate,
    ProviderConfigUpdate,
    QuoteCreate,
    QuoteOut,
    WebhookCreate,
    WebhookOut,
)
from .providers import get_provider
from .services import add_payment, create_order, emit_order_event, validate_webhook_url


router = APIRouter(prefix="/v1")


def owned_order(db: Session, order_id: str, principal: Principal) -> Order:
    order = db.get(Order, order_id)
    if order is None or (principal.id != "bootstrap" and "admin" not in principal.scopes and order.api_key_id != principal.id):
        raise HTTPException(status_code=404, detail="Order not found")
    return order


@router.get("/products", response_model=list[ProductPublic])
def list_products(
    kind: str | None = Query(default=None, pattern="^(stars|premium)$"),
    db: Session = Depends(get_db),
    _: Principal = Depends(require_scope("products:read")),
):
    query = select(Product).where(Product.active.is_(True))
    if kind:
        query = query.where(Product.kind == kind)
    return db.scalars(query.order_by(Product.sort_order, Product.created_at)).all()


@router.post("/quotes", response_model=QuoteOut)
def create_quote(
    data: QuoteCreate,
    db: Session = Depends(get_db),
    _: Principal = Depends(require_scope("products:read")),
):
    product = db.get(Product, data.product_id)
    if product is None or not product.active:
        raise HTTPException(status_code=404, detail="Product not found")
    return QuoteOut(
        quote_id=str(uuid4()),
        product_id=product.id,
        amount=product.price_amount,
        currency=product.price_currency,
        expires_at=utcnow() + timedelta(minutes=5),
    )


@router.post("/orders", response_model=OrderOut, status_code=status.HTTP_201_CREATED)
def post_order(
    data: OrderCreate,
    idempotency_key: str = Header(min_length=8, max_length=100, alias="Idempotency-Key"),
    db: Session = Depends(get_db),
    principal: Principal = Depends(require_scope("orders:write")),
):
    return create_order(db, principal, idempotency_key, data)


@router.get("/orders", response_model=OrderList)
def list_orders(
    limit: int = Query(default=20, ge=1, le=100),
    db: Session = Depends(get_db),
    principal: Principal = Depends(require_scope("orders:read")),
):
    query = select(Order)
    if principal.id != "bootstrap" and "admin" not in principal.scopes:
        query = query.where(Order.api_key_id == principal.id)
    items = db.scalars(query.order_by(Order.created_at.desc()).limit(limit)).all()
    return OrderList(items=items)


@router.get("/orders/{order_id}", response_model=OrderOut)
def get_order(
    order_id: str,
    db: Session = Depends(get_db),
    principal: Principal = Depends(require_scope("orders:read")),
):
    return owned_order(db, order_id, principal)


@router.post("/orders/{order_id}/cancel", response_model=OrderOut)
def cancel_order(
    order_id: str,
    db: Session = Depends(get_db),
    principal: Principal = Depends(require_scope("orders:write")),
):
    order = owned_order(db, order_id, principal)
    if order.status not in {"created", "awaiting_payment"}:
        raise HTTPException(status_code=409, detail="Only unpaid orders can be canceled")
    order.status = "canceled"
    order.updated_at = utcnow()
    emit_order_event(db, order)
    db.commit()
    db.refresh(order)
    return order


@router.post("/orders/{order_id}/payments", response_model=PaymentOut, status_code=status.HTTP_201_CREATED)
def post_payment(
    order_id: str,
    data: PaymentCreate,
    db: Session = Depends(get_db),
    principal: Principal = Depends(require_scope("payments:write")),
):
    order = owned_order(db, order_id, principal)
    payment = add_payment(db, order, principal, data)
    db.commit()
    db.refresh(payment)
    return payment


@router.get("/payments/{payment_id}", response_model=PaymentOut)
def get_payment(
    payment_id: str,
    db: Session = Depends(get_db),
    principal: Principal = Depends(require_scope("orders:read")),
):
    payment = db.get(Payment, payment_id)
    if payment is None or (principal.id != "bootstrap" and "admin" not in principal.scopes and payment.api_key_id != principal.id):
        raise HTTPException(status_code=404, detail="Payment not found")
    return payment


@router.get("/webhooks", response_model=list[WebhookOut])
def list_webhooks(
    db: Session = Depends(get_db),
    principal: Principal = Depends(require_scope("webhooks:manage")),
):
    rows = db.scalars(select(WebhookEndpoint).where(WebhookEndpoint.api_key_id == principal.id)).all()
    return [
        WebhookOut(id=row.id, url=row.url, events=row.events.split(","), active=row.active, created_at=row.created_at)
        for row in rows
    ]


@router.post("/webhooks", response_model=WebhookOut, status_code=status.HTTP_201_CREATED)
def create_webhook(
    data: WebhookCreate,
    db: Session = Depends(get_db),
    principal: Principal = Depends(require_scope("webhooks:manage")),
):
    validate_webhook_url(data.url)
    allowed = {"order.updated"}
    events = sorted(set(data.events))
    if not events or not set(events).issubset(allowed):
        raise HTTPException(status_code=422, detail="Unsupported webhook event")
    row = WebhookEndpoint(
        api_key_id=principal.id,
        url=data.url,
        secret=encrypt_secret(data.secret),
        events=",".join(events),
    )
    db.add(row)
    db.commit()
    db.refresh(row)
    return WebhookOut(id=row.id, url=row.url, events=events, active=row.active, created_at=row.created_at)


@router.delete("/webhooks/{webhook_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_webhook(
    webhook_id: str,
    db: Session = Depends(get_db),
    principal: Principal = Depends(require_scope("webhooks:manage")),
):
    row = db.scalar(
        select(WebhookEndpoint).where(
            WebhookEndpoint.id == webhook_id, WebhookEndpoint.api_key_id == principal.id
        )
    )
    if not row:
        raise HTTPException(status_code=404, detail="Webhook not found")
    db.delete(row)
    db.commit()


@router.get("/admin/products", response_model=list[ProductOut])
def admin_products(
    db: Session = Depends(get_db), _: Principal = Depends(require_scope("admin"))
):
    return db.scalars(select(Product).order_by(Product.sort_order, Product.created_at)).all()


@router.post("/admin/products", response_model=ProductOut, status_code=status.HTTP_201_CREATED)
def create_product(
    data: ProductCreate,
    db: Session = Depends(get_db),
    _: Principal = Depends(require_scope("admin")),
):
    if db.scalar(select(Product).where(Product.sku == data.sku)):
        raise HTTPException(status_code=409, detail="Product SKU already exists")
    row = Product(**data.model_dump())
    db.add(row)
    db.commit()
    db.refresh(row)
    return row


@router.patch("/admin/products/{product_id}", response_model=ProductOut)
def update_product(
    product_id: str,
    data: ProductUpdate,
    db: Session = Depends(get_db),
    _: Principal = Depends(require_scope("admin")),
):
    row = db.get(Product, product_id)
    if not row:
        raise HTTPException(status_code=404, detail="Product not found")
    if row.kind == "stars" and data.provider == "telegram_bot":
        raise HTTPException(status_code=422, detail="telegram_bot provider supports Premium only")
    for key, value in data.model_dump(exclude_unset=True).items():
        setattr(row, key, value)
    row.updated_at = utcnow()
    db.commit()
    db.refresh(row)
    return row


@router.post("/admin/api-keys", response_model=ApiKeyCreated, status_code=status.HTTP_201_CREATED)
def create_api_key(
    data: ApiKeyCreate,
    db: Session = Depends(get_db),
    _: Principal = Depends(require_scope("admin")),
):
    allowed = {"products:read", "orders:read", "orders:write", "payments:write", "webhooks:manage", "admin"}
    scopes = sorted(set(data.scopes))
    if not scopes or not set(scopes).issubset(allowed):
        raise HTTPException(status_code=422, detail="Invalid API key scope")
    secret = generate_api_key()
    row = ApiKey(
        name=data.name,
        key_prefix=secret[:12],
        secret_hash=hash_api_key(secret),
        scopes=",".join(scopes),
    )
    db.add(row)
    db.commit()
    db.refresh(row)
    return ApiKeyCreated(id=row.id, name=row.name, key=secret, prefix=row.key_prefix, scopes=scopes)


@router.get("/admin/provider-status")
def provider_status(_: Principal = Depends(require_scope("admin"))):
    settings = get_effective_settings()
    return {
        "default_provider": settings.default_provider,
        "fragment_session_configured": all(
            [settings.fragment_stel_ssid, settings.fragment_stel_dt, settings.fragment_stel_token]
        ),
        "fragment_wallet_configured": bool(settings.fragment_wallet_seed),
        "fragment_ton_rpc_configured": bool(settings.fragment_ton_api_key),
        "fragment_wallet_version": settings.fragment_wallet_version,
        "fragment_show_sender": settings.fragment_show_sender,
        "fragment_low_balance_ton": settings.fragment_low_balance_ton,
        "fragment_configured": all(
            [
                settings.fragment_stel_ssid,
                settings.fragment_stel_dt,
                settings.fragment_stel_token,
                settings.fragment_stel_ton_token,
                settings.fragment_wallet_seed,
                settings.fragment_ton_api_key,
            ]
        ),
        "telegram_bot_configured": bool(settings.telegram_bot_token),
        "marketapp_enabled": False,
    }


@router.patch("/admin/provider-config")
def update_provider_config(
    data: ProviderConfigUpdate,
    db: Session = Depends(get_db),
    _: Principal = Depends(require_scope("admin")),
):
    values = data.model_dump(exclude_unset=True)
    if not values:
        raise HTTPException(status_code=422, detail="No setting was provided")
    for key, value in values.items():
        if hasattr(value, "get_secret_value"):
            value = value.get_secret_value().strip()
        set_runtime_value(db, key, value)
    db.commit()
    settings = get_effective_settings(db)
    return {
        "ok": True,
        "fragment_wallet_configured": bool(settings.fragment_wallet_seed),
        "fragment_ton_rpc_configured": bool(settings.fragment_ton_api_key),
        "fragment_wallet_version": settings.fragment_wallet_version,
        "fragment_show_sender": settings.fragment_show_sender,
        "fragment_low_balance_ton": settings.fragment_low_balance_ton,
    }


@router.post("/admin/fragment/auth", response_model=FragmentAuthOut, status_code=status.HTTP_202_ACCEPTED)
async def start_fragment_auth(
    data: FragmentAuthStart,
    db: Session = Depends(get_db),
    _: Principal = Depends(require_scope("admin")),
):
    settings = get_effective_settings(db)
    if not settings.fragment_wallet_seed:
        raise HTTPException(status_code=409, detail="Register the wallet seed before Telegram login")
    active = db.scalar(
        select(FragmentAuthJob).where(
            FragmentAuthJob.status.in_(["pending", "running", "waiting_confirmation", "finalizing"])
        )
    )
    if active:
        raise HTTPException(status_code=409, detail={"message": "An authentication is already running", "job_id": active.id})
    job = FragmentAuthJob(
        status="pending",
        encrypted_phone=encrypt_secret(data.phone.get_secret_value()),
        progress="queued",
    )
    db.add(job)
    db.commit()
    db.refresh(job)
    launch_auth(job.id)
    return FragmentAuthOut.model_validate(job, from_attributes=True)


@router.get("/admin/fragment/auth/{job_id}", response_model=FragmentAuthOut)
def fragment_auth_status(
    job_id: str,
    db: Session = Depends(get_db),
    _: Principal = Depends(require_scope("admin")),
):
    job = db.get(FragmentAuthJob, job_id)
    if job is None:
        raise HTTPException(status_code=404, detail="Authentication job not found")
    return FragmentAuthOut.model_validate(job, from_attributes=True)


@router.delete("/admin/fragment/session")
def clear_fragment_session(
    db: Session = Depends(get_db),
    _: Principal = Depends(require_scope("admin")),
):
    clear_runtime_values(
        db,
        [
            "fragment_stel_ssid",
            "fragment_stel_dt",
            "fragment_stel_token",
            "fragment_stel_ton_token",
        ],
    )
    db.commit()
    return {"ok": True}


@router.post("/admin/provider-check")
async def check_provider(
    provider: str = Query(default="fragment", pattern="^(fragment|telegram_bot|mock)$"),
    _: Principal = Depends(require_scope("admin")),
):
    try:
        return await get_provider(provider).check_connection()
    except Exception as exc:
        raise HTTPException(status_code=503, detail=str(exc)[:500]) from exc

