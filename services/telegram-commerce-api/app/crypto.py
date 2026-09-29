from cryptography.fernet import Fernet, InvalidToken

from .config import get_settings


def _fernet() -> Fernet:
    try:
        return Fernet(get_settings().data_encryption_key.encode("ascii"))
    except (ValueError, UnicodeEncodeError) as exc:
        raise RuntimeError("DATA_ENCRYPTION_KEY is not a valid Fernet key") from exc


def encrypt_secret(value: str) -> str:
    return _fernet().encrypt(value.encode("utf-8")).decode("ascii")


def decrypt_secret(value: str) -> str:
    try:
        return _fernet().decrypt(value.encode("ascii")).decode("utf-8")
    except (InvalidToken, UnicodeEncodeError) as exc:
        raise RuntimeError("Encrypted secret cannot be decrypted") from exc

