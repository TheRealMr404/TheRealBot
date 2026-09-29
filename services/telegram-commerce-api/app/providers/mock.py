from .base import PurchaseProvider, PurchaseResult
from ..models import Order


class MockProvider(PurchaseProvider):
    async def check_connection(self) -> dict:
        return {"ok": True, "provider": "mock", "message": "Mock provider is ready"}

    async def purchase(self, order: Order) -> PurchaseResult:
        if order.recipient.lower() in {"fail", "error"}:
            raise RuntimeError("Mock provider failure")
        return PurchaseResult(
            provider_reference=f"mock:{order.id}",
            transaction_hash=f"mock-tx:{order.id}",
            data={"simulated": True},
        )

