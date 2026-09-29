from .base import PurchaseProvider, PurchaseResult
from ..models import Order


class MockProvider(PurchaseProvider):
    async def purchase(self, order: Order) -> PurchaseResult:
        if order.recipient.lower() in {"fail", "error"}:
            raise RuntimeError("Mock provider failure")
        return PurchaseResult(
            provider_reference=f"mock:{order.id}",
            transaction_hash=f"mock-tx:{order.id}",
            data={"simulated": True},
        )

