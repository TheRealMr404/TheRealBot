from .base import PurchaseProvider
from .fragment import FragmentProvider
from .mock import MockProvider
from .telegram_bot import TelegramBotProvider
from ..config import get_settings


def get_provider(name: str) -> PurchaseProvider:
    settings = get_settings()
    if name == "mock":
        return MockProvider()
    if name == "fragment":
        return FragmentProvider(settings)
    if name == "telegram_bot":
        return TelegramBotProvider(settings)
    raise RuntimeError(f"Unknown provider: {name}")

