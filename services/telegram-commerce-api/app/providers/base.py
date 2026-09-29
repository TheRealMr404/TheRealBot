from abc import ABC, abstractmethod
from dataclasses import dataclass, field

from ..models import Order


class ProviderDefinitiveError(RuntimeError):
    """The provider rejected the purchase before it could be completed."""


@dataclass
class PurchaseResult:
    provider_reference: str | None = None
    transaction_hash: str | None = None
    data: dict = field(default_factory=dict)


class PurchaseProvider(ABC):
    async def check_connection(self) -> dict:
        return {"ok": True}

    @abstractmethod
    async def purchase(self, order: Order) -> PurchaseResult:
        raise NotImplementedError

