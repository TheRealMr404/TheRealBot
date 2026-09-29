from contextlib import asynccontextmanager

from fastapi import FastAPI

from .api import router
from .db import Base, engine


@asynccontextmanager
async def lifespan(_: FastAPI):
    Base.metadata.create_all(bind=engine)
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

