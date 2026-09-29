import os
from pathlib import Path

import pytest


TEST_DB = Path(__file__).parent / "commerce-test.db"
os.environ["APP_ENV"] = "test"
os.environ["DATABASE_URL"] = f"sqlite:///{TEST_DB.as_posix()}"
os.environ["API_BOOTSTRAP_KEY"] = "test-bootstrap-key-that-is-longer-than-32-characters"
os.environ["DATA_ENCRYPTION_KEY"] = "MDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA="
os.environ["DEFAULT_PROVIDER"] = "mock"
os.environ["WEBHOOK_BLOCK_PRIVATE_NETWORKS"] = "false"

from fastapi.testclient import TestClient

from app.db import Base, engine
from app.main import app


@pytest.fixture(autouse=True)
def clean_database():
    Base.metadata.drop_all(bind=engine)
    Base.metadata.create_all(bind=engine)
    yield


@pytest.fixture
def client():
    with TestClient(app) as value:
        yield value


@pytest.fixture
def headers():
    return {"X-API-Key": os.environ["API_BOOTSTRAP_KEY"]}


@pytest.fixture
def product(client, headers):
    response = client.post(
        "/v1/admin/products",
        headers=headers,
        json={
            "sku": "stars-100",
            "kind": "stars",
            "title": "100 Telegram Stars",
            "units": 100,
            "price_amount": 250000,
            "price_currency": "IRR",
            "provider": "mock",
        },
    )
    assert response.status_code == 201, response.text
    return response.json()

