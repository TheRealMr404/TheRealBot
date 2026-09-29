from .base import PurchaseProvider
from .fragment import FragmentProvider
from .mock import MockProvider
from .telegram_bot import TelegramBotProvider
from ..runtime_config import get_effective_settings


def get_provider(name: str) -> PurchaseProvider:
    settings = get_effective_settings()
    if name == "mock":
        return MockProvider()
    if name == "fragment":
        return FragmentProvider(settings)
    if name == "telegram_bot":
        return TelegramBotProvider(settings)
    raise RuntimeError(f"Unknown provider: {name}")

