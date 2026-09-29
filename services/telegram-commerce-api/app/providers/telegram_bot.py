import httpx

from .base import ProviderDefinitiveError, PurchaseProvider, PurchaseResult
from ..config import Settings
from ..models import Order


class TelegramBotProvider(PurchaseProvider):
    STAR_COST = {3: 1000, 6: 1500, 12: 2500}

    def __init__(self, settings: Settings):
        if not settings.telegram_bot_token:
            raise ProviderDefinitiveError("TELEGRAM_BOT_TOKEN is not configured")
        self.token = settings.telegram_bot_token

    async def purchase(self, order: Order) -> PurchaseResult:
        if order.kind != "premium":
            raise ProviderDefinitiveError("telegram_bot provider supports Premium only")
        if not order.recipient.isdigit():
            raise ProviderDefinitiveError("telegram_bot provider requires a numeric Telegram user id")
        months = int(order.months or 0)
        if months not in self.STAR_COST:
            raise ProviderDefinitiveError("Premium duration must be 3, 6 or 12 months")
        payload = {
            "user_id": int(order.recipient),
            "month_count": months,
            "star_count": self.STAR_COST[months],
        }
        async with httpx.AsyncClient(timeout=20) as client:
            response = await client.post(
                f"https://api.telegram.org/bot{self.token}/giftPremiumSubscription", json=payload
            )
        data = response.json()
        if response.status_code >= 400 or not data.get("ok"):
            raise ProviderDefinitiveError(data.get("description") or f"Telegram HTTP {response.status_code}")
        return PurchaseResult(provider_reference=f"telegram:{order.id}", data={"ok": True})

