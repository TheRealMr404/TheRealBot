from contextlib import asynccontextmanager

from fastapi import FastAPI
from sqlalchemy import update

from .api import router
from .db import Base, SessionLocal, engine
from .models import FragmentAuthJob, utcnow


@asynccontextmanager
async def lifespan(_: FastAPI):
    Base.metadata.create_all(bind=engine)
    db = SessionLocal()
    try:
        db.execute(
            update(FragmentAuthJob)
            .where(
                FragmentAuthJob.status.in_(
                    ["pending", "running", "waiting_confirmation", "finalizing"]
                )
            )
            .values(
                status="failed",
                progress="interrupted",
                error_message="Authentication was interrupted by a service restart",
                encrypted_phone=None,
                completed_at=utcnow(),
                updated_at=utcnow(),
            )
        )
        db.commit()
    finally:
        db.close()
    yield


app = FastAPI(
    title="Mirza Telegram Commerce API",
    version="1.0.0",
    description="Provider-independent API for Telegram Stars and Premium orders.",
    lifespan=lifespan,
)
app.include_router(router)


@app.get("/healthz", tags=["system"])
def healthz():
    return {"status": "ok"}

