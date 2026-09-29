import asyncio

from app.db import SessionLocal
from app.models import FragmentAuthJob, ProviderConfig
from app.services import process_one_order


def test_health_and_auth(client):
    assert client.get("/healthz").json() == {"status": "ok"}
    response = client.get("/v1/products")
    assert response.status_code == 401


def test_product_quote_and_paid_order(client, headers, product):
    products = client.get("/v1/products", headers=headers)
    assert products.status_code == 200
    assert products.json()[0]["sku"] == "stars-100"

    quote = client.post("/v1/quotes", headers=headers, json={"product_id": product["id"]})
    assert quote.status_code == 200
    assert quote.json()["amount"] == 250000

    payload = {
        "product_id": product["id"],
        "recipient": "@durov",
        "client_reference": "bot-order-10",
        "payment": {
            "external_reference": "wallet-ledger-10",
            "amount": 250000,
            "currency": "IRR",
        },
    }
    response = client.post(
        "/v1/orders", headers={**headers, "Idempotency-Key": "bot-order-10"}, json=payload
    )
    assert response.status_code == 201, response.text
    order = response.json()
    assert order["recipient"] == "durov"
    assert order["status"] == "queued"

    repeated = client.post(
        "/v1/orders", headers={**headers, "Idempotency-Key": "bot-order-10"}, json=payload
    )
    assert repeated.status_code == 201
    assert repeated.json()["id"] == order["id"]

    assert asyncio.run(process_one_order(order["id"])) is True
    completed = client.get(f"/v1/orders/{order['id']}", headers=headers)
    assert completed.json()["status"] == "fulfilled"
    assert completed.json()["transaction_hash"].startswith("mock-tx:")


def test_idempotency_conflict_and_payment_validation(client, headers, product):
    first = client.post(
        "/v1/orders",
        headers={**headers, "Idempotency-Key": "same-key-123"},
        json={"product_id": product["id"], "recipient": "first_user"},
    )
    assert first.status_code == 201
    conflict = client.post(
        "/v1/orders",
        headers={**headers, "Idempotency-Key": "same-key-123"},
        json={"product_id": product["id"], "recipient": "other_user"},
    )
    assert conflict.status_code == 409
    bad_payment = client.post(
        f"/v1/orders/{first.json()['id']}/payments",
        headers=headers,
        json={"external_reference": "bad-amount", "amount": 1, "currency": "IRR"},
    )
    assert bad_payment.status_code == 422


def test_uncertain_provider_result_is_not_retried(client, headers, product):
    response = client.post(
        "/v1/orders",
        headers={**headers, "Idempotency-Key": "failure-key-1"},
        json={
            "product_id": product["id"],
            "recipient": "fail",
            "payment": {
                "external_reference": "failure-payment-1",
                "amount": 250000,
                "currency": "IRR",
            },
        },
    )
    order_id = response.json()["id"]
    asyncio.run(process_one_order(order_id))
    order = client.get(f"/v1/orders/{order_id}", headers=headers).json()
    assert order["status"] == "reconciliation_required"
    assert order["attempt_count"] == 1
    assert asyncio.run(process_one_order(order_id)) is False


def test_scoped_key_cannot_read_other_clients_order(client, headers, product):
    key_one = client.post(
        "/v1/admin/api-keys",
        headers=headers,
        json={"name": "bot-one", "scopes": ["products:read", "orders:read", "orders:write"]},
    ).json()["key"]
    key_two = client.post(
        "/v1/admin/api-keys",
        headers=headers,
        json={"name": "bot-two", "scopes": ["products:read", "orders:read", "orders:write"]},
    ).json()["key"]
    order = client.post(
        "/v1/orders",
        headers={"X-API-Key": key_one, "Idempotency-Key": "client-one-order"},
        json={"product_id": product["id"], "recipient": "durov"},
    ).json()
    assert client.get(f"/v1/orders/{order['id']}", headers={"X-API-Key": key_two}).status_code == 404


def test_webhook_secret_is_not_returned(client, headers):
    response = client.post(
        "/v1/webhooks",
        headers=headers,
        json={
            "url": "https://hooks.example.com/telegram-commerce",
            "secret": "a-webhook-secret-that-is-at-least-32-characters",
            "events": ["order.updated"],
        },
    )
    assert response.status_code == 201, response.text
    assert "secret" not in response.json()


def test_provider_dashboard_and_live_check_contract(client, headers):
    status = client.get("/v1/admin/provider-status", headers=headers)
    assert status.status_code == 200
    assert status.json()["marketapp_enabled"] is False
    assert status.json()["fragment_wallet_version"] == "V5R1"

    check = client.post("/v1/admin/provider-check?provider=mock", headers=headers)
    assert check.status_code == 200
    assert check.json() == {"ok": True, "provider": "mock", "message": "Mock provider is ready"}


def test_provider_config_is_encrypted_and_never_returned(client, headers):
    seed = "one two three four five six seven eight nine ten eleven twelve"
    ton_key = "private-ton-api-key-value"
    response = client.patch(
        "/v1/admin/provider-config",
        headers=headers,
        json={
            "fragment_wallet_seed": seed,
            "fragment_ton_api_key": ton_key,
            "fragment_wallet_version": "V4R2",
            "fragment_show_sender": True,
            "fragment_low_balance_ton": 2.5,
        },
    )
    assert response.status_code == 200, response.text
    body = response.text
    assert seed not in body
    assert ton_key not in body
    assert response.json()["fragment_wallet_version"] == "V4R2"

    db = SessionLocal()
    try:
        seed_row = db.get(ProviderConfig, "fragment_wallet_seed")
        key_row = db.get(ProviderConfig, "fragment_ton_api_key")
        assert seed_row is not None and seed not in seed_row.encrypted_value
        assert key_row is not None and ton_key not in key_row.encrypted_value
    finally:
        db.close()

    status = client.get("/v1/admin/provider-status", headers=headers)
    assert status.status_code == 200
    assert status.json()["fragment_wallet_configured"] is True
    assert seed not in status.text
    assert ton_key not in status.text


def test_fragment_auth_phone_is_encrypted(monkeypatch, client, headers):
    seed = "one two three four five six seven eight nine ten eleven twelve"
    client.patch(
        "/v1/admin/provider-config",
        headers=headers,
        json={"fragment_wallet_seed": seed},
    )
    launched = []
    monkeypatch.setattr("app.api.launch_auth", launched.append)
    phone = "+989121234567"
    response = client.post(
        "/v1/admin/fragment/auth", headers=headers, json={"phone": phone}
    )
    assert response.status_code == 202, response.text
    assert phone not in response.text
    job_id = response.json()["id"]
    assert launched == [job_id]

    db = SessionLocal()
    try:
        job = db.get(FragmentAuthJob, job_id)
        assert job is not None
        assert job.encrypted_phone and phone not in job.encrypted_phone
    finally:
        db.close()

