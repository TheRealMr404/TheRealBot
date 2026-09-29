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

    def _client(self):
        try:
            from FragmentAPI import FragmentClient
        except ImportError as exc:
            raise ProviderDefinitiveError("fragment-api-py is not installed") from exc
        return FragmentClient(
            cookies={
                "stel_ssid": self.settings.fragment_stel_ssid,
                "stel_dt": self.settings.fragment_stel_dt,
                "stel_token": self.settings.fragment_stel_token,
                "stel_ton_token": self.settings.fragment_stel_ton_token,
            },
            seed=self.settings.fragment_wallet_seed,
            api_key=self.settings.fragment_ton_api_key,
            wallet_version=self.settings.fragment_wallet_version,
        )

    async def check_connection(self) -> dict:
        async with self._client() as client:
            profile = await client.get_profile()
            wallet = await client.get_wallet()
        profile_data = profile.model_dump(mode="json") if hasattr(profile, "model_dump") else {}
        wallet_data = wallet.model_dump(mode="json") if hasattr(wallet, "model_dump") else {}
        balance = (
            wallet_data.get("gram_balance")
            or wallet_data.get("ton_balance")
            or getattr(wallet, "gram_balance", None)
            or getattr(wallet, "ton_balance", None)
        )
        address = wallet_data.get("address") or getattr(wallet, "address", None)
        profile_name = profile_data.get("name") or getattr(profile, "name", None)
        try:
            balance_value = float(balance) if balance is not None else None
        except (TypeError, ValueError):
            balance_value = None
        return {
            "ok": True,
            "provider": "fragment",
            "profile_name": str(profile_name) if profile_name else None,
            "wallet_address": str(address) if address else None,
            "balance_ton": balance_value,
            "low_balance": balance_value is not None
            and self.settings.fragment_low_balance_ton > 0
            and balance_value < self.settings.fragment_low_balance_ton,
            "wallet_version": self.settings.fragment_wallet_version,
        }

    async def purchase(self, order: Order) -> PurchaseResult:
        # The provider keeps the unofficial Fragment surface behind one adapter.
        async with self._client() as client:
            if order.kind == "stars":
                result = await client.purchase_stars(
                    order.recipient, int(order.units or 0), self.settings.fragment_show_sender
                )
            elif order.kind == "premium":
                result = await client.purchase_premium(
                    order.recipient, int(order.months or 0), self.settings.fragment_show_sender
                )
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

