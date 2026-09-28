#!/usr/bin/env python3
import asyncio
import json
import sys
from typing import Any


def output(data: dict[str, Any]) -> None:
    sys.stdout.write(json.dumps(data, ensure_ascii=False, default=str))
    sys.stdout.flush()


def public_value(value: Any) -> Any:
    if value is None or isinstance(value, (str, int, float, bool)):
        return value
    if isinstance(value, dict):
        return {str(key): public_value(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [public_value(item) for item in value]
    if hasattr(value, "model_dump"):
        return public_value(value.model_dump())
    if hasattr(value, "__dict__"):
        return {
            key: public_value(item)
            for key, item in vars(value).items()
            if not key.startswith("_")
        }
    return str(value)


def error_payload(exc: Exception) -> dict[str, Any]:
    name = exc.__class__.__name__
    retry_safe = name in {
        "ConfigurationError",
        "CookieError",
        "UserNotFoundError",
        "AlreadySubscribedError",
        "VerificationError",
        "ParseError",
    }
    return {
        "ok": False,
        "code": name,
        "message": str(exc)[:1000],
        "retry_safe": retry_safe,
    }


async def run(payload: dict[str, Any]) -> dict[str, Any]:
    try:
        from FragmentAPI import FragmentClient
    except Exception as exc:
        return {
            "ok": False,
            "code": "RUNTIME_MISSING",
            "message": f"fragment-api-py is unavailable: {exc}",
            "retry_safe": True,
        }

    action = str(payload.get("action", "health"))
    if action == "health":
        return {"ok": True, "runtime": "fragment-api-py"}

    config = payload.get("config") or {}
    cookies = config.get("cookies") or {}
    kwargs = {
        "cookies": cookies,
        "seed": str(config.get("seed", "")),
        "api_key": str(config.get("api_key", "")),
        "wallet_version": str(config.get("wallet_version", "V5R1")),
        "api_provider": str(config.get("api_provider", "toncenter")),
    }

    try:
        async with FragmentClient(**kwargs) as client:
            if action == "session":
                profile = await client.get_profile()
                return {
                    "ok": True,
                    "profile": public_value(profile),
                }

            if action == "connection":
                from FragmentAPI.utils.wallet import fetch_wallet_info

                wallet = await fetch_wallet_info(client)
                await client.get_stars_prices()
                profile = None
                try:
                    profile = await client.get_profile()
                except Exception:
                    pass
                return {
                    "ok": True,
                    "wallet": public_value(wallet),
                    "profile": public_value(profile),
                }

            if action == "purchase":
                username = str(payload.get("username", "")).strip()
                product = str(payload.get("product", ""))
                amount = int(payload.get("amount", 0))
                show_sender = bool(config.get("show_sender", False))
                payment_method = str(config.get("payment_method", "ton"))
                if product == "stars":
                    result = await client.purchase_stars(
                        username,
                        amount,
                        show_sender=show_sender,
                        payment_method=payment_method,
                    )
                elif product == "premium":
                    result = await client.purchase_premium(
                        username,
                        amount,
                        show_sender=show_sender,
                        payment_method=payment_method,
                    )
                else:
                    return {
                        "ok": False,
                        "code": "INVALID_PRODUCT",
                        "message": "Unsupported Fragment product.",
                        "retry_safe": True,
                    }
                data = public_value(result)
                transaction_id = ""
                if isinstance(data, dict):
                    transaction_id = str(
                        data.get("transaction_id")
                        or data.get("tx_hash")
                        or data.get("hash")
                        or ""
                    )
                return {
                    "ok": True,
                    "transaction_id": transaction_id,
                    "result": data,
                }

            return {
                "ok": False,
                "code": "UNKNOWN_ACTION",
                "message": "Unknown worker action.",
                "retry_safe": True,
            }
    except Exception as exc:
        return error_payload(exc)


def main() -> None:
    try:
        payload = json.loads(sys.stdin.read() or "{}")
        output(asyncio.run(run(payload)))
    except Exception as exc:
        output(error_payload(exc))
        sys.exit(1)


if __name__ == "__main__":
    main()
