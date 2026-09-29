import asyncio
import logging

from .config import get_settings
from .db import Base, engine
from .services import deliver_next_event, process_next_order


logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")


async def run() -> None:
    Base.metadata.create_all(bind=engine)
    delay = get_settings().worker_poll_seconds
    while True:
        try:
            worked = await process_next_order()
            delivered = await deliver_next_event()
            if not worked and not delivered:
                await asyncio.sleep(delay)
        except Exception:
            logging.exception("Worker iteration failed")
            await asyncio.sleep(delay)


if __name__ == "__main__":
    asyncio.run(run())

