from .base import ProviderDefinitiveError, PurchaseProvider, PurchaseResult
from ..config import Settings
from ..models import Order


class FragmentProvider(PurchaseProvider):
    """Direct Fragment.com provider using KYC cookies; MarketApp is never used."""

    def __init__(self, settings: Settings):
        self.settings = settings
        required = {
            "FRAGMENT_STEL_SSID": settings.fragment_stel_ssid,
            "FRAGMENT_STEL_DT": settings.fragment_stel_dt,
            "FRAGMENT_STEL_TOKEN": settings.fragment_stel_token,
            "FRAGMENT_STEL_TON_TOKEN": settings.fragment_stel_ton_token,
            "FRAGMENT_WALLET_SEED": settings.fragment_wallet_seed,
            "FRAGMENT_TON_API_KEY": settings.fragment_ton_api_key,
        }
        missing = [name for name, value in required.items() if not value]
        if missing:
            raise ProviderDefinitiveError("Missing Fragment configuration: " + ", ".join(missing))

    async def purchase(self, order: Order) -> PurchaseResult:
        try:
            from FragmentAPI import FragmentClient
        except ImportError as exc:
            raise ProviderDefinitiveError("fragment-api-py is not installed") from exc

        cookies = {
            "stel_ssid": self.settings.fragment_stel_ssid,
            "stel_dt": self.settings.fragment_stel_dt,
            "stel_token": self.settings.fragment_stel_token,
            "stel_ton_token": self.settings.fragment_stel_ton_token,
        }
        # The provider keeps the unofficial Fragment surface behind one adapter.
        async with FragmentClient(
            cookies=cookies,
            seed=self.settings.fragment_wallet_seed,
            api_key=self.settings.fragment_ton_api_key,
            wallet_version=self.settings.fragment_wallet_version,
        ) as client:
            if order.kind == "stars":
                result = await client.purchase_stars(order.recipient, int(order.units or 0))
            elif order.kind == "premium":
                result = await client.purchase_premium(order.recipient, int(order.months or 0))
            else:
                raise ProviderDefinitiveError(f"Unsupported product kind: {order.kind}")

        tx_hash = getattr(result, "transaction_id", None) or getattr(result, "tx_hash", None)
        request_id = getattr(result, "request_id", None) or getattr(result, "id", None)
        raw = result.model_dump(mode="json") if hasattr(result, "model_dump") else {"result": str(result)}
        return PurchaseResult(
            provider_reference=str(request_id) if request_id is not None else None,
            transaction_hash=str(tx_hash) if tx_hash is not None else None,
            data=raw,
        )

