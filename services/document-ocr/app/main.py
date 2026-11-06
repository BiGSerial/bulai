import asyncio
import hmac
import logging
import os
import threading
import tempfile
import time
import uuid
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Callable

from starlette.concurrency import run_in_threadpool
from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, Request, UploadFile

from .config import Settings
from .engine import DocumentOcrEngine, EngineError
from .layout import blocks_to_text
from .runtime import ActivityTracker, Watchdog
from .worker import ProcessExecutor, WorkerCrashed, build_executor
from .models import (
    BlockModel,
    InputDocument,
    PageModel,
    ParsedDocument,
    ParseResponse,
    ProcessingModel,
)

logger = logging.getLogger("document-ocr")


def create_app(
    settings: Settings | None = None,
    engine: DocumentOcrEngine | None = None,
    exit_fn: Callable[[int], object] = os._exit,
    restart_delay: float = 1.0,
    watchdog_interval: float = 5.0,
    clock: Callable[[], float] | None = None,
    executor_mode: str | None = None,
) -> FastAPI:
    settings = settings or Settings.from_env()
    tracker = ActivityTracker(clock=clock) if clock else ActivityTracker()
    # Engine injetado (testes) roda no próprio processo; em produção o engine vive num processo filho e o watchdog
    # mata só o filho, mantendo o servidor (e /status) de pé.
    executor = build_executor(settings, engine, executor_mode)
    on_timeout = executor.kill if isinstance(executor, ProcessExecutor) else None
    watchdog = Watchdog(tracker, settings.request_timeout, exit_fn, watchdog_interval, on_timeout=on_timeout)

    @asynccontextmanager
    async def lifespan(_: FastAPI):
        watchdog.start()
        executor.start()
        try:
            yield
        finally:
            watchdog.stop()
            executor.stop()

    app = FastAPI(title="BULA-IA Document OCR", docs_url=None, redoc_url=None, lifespan=lifespan)
    # O OCR consome CPU e RAM de forma intensa: poucas execuções simultâneas (padrão 1) e fora do event loop,
    # para que /health e /status continuem respondendo enquanto um documento longo é processado.
    limiter = asyncio.Semaphore(settings.max_concurrency)
    app.state.tracker = tracker
    app.state.watchdog = watchdog
    app.state.executor = executor

    def authorize(authorization: str | None = Header(default=None)) -> None:
        if settings.token is None:
            return
        expected = f"Bearer {settings.token}"
        if authorization is None or not hmac.compare_digest(authorization, expected):
            raise HTTPException(status_code=401, detail="unauthorized")

    @app.get("/health")
    def health() -> dict[str, str]:
        return {"status": "ok"}

    @app.get("/status")
    def status() -> dict:
        # Só memória do processo pai: nunca espera o engine.
        snapshot = tracker.snapshot()
        if isinstance(executor, ProcessExecutor):
            snapshot["worker"] = {"alive": executor.alive(), "restarts": executor.restarts}
        return snapshot

    def authorize_admin(authorization: str | None = Header(default=None)) -> None:
        if settings.token is None:
            raise HTTPException(status_code=404, detail="not found")
        expected = f"Bearer {settings.token}"
        if authorization is None or not hmac.compare_digest(authorization, expected):
            raise HTTPException(status_code=401, detail="unauthorized")

    @app.post("/admin/restart", status_code=202, dependencies=[Depends(authorize_admin)])
    def restart() -> dict[str, str]:
        # Encerra o processo depois da resposta; o Docker (restart: unless-stopped) religa o container.
        logger.warning("ocr_restart_requested delay=%.1f", restart_delay)
        timer = threading.Timer(restart_delay, exit_fn, args=(0,))
        timer.daemon = True
        timer.start()
        return {"status": "restarting"}

    @app.post("/v1/documents/parse", response_model=ParseResponse, dependencies=[Depends(authorize)])
    async def parse(
        request: Request,
        file: UploadFile = File(...),
        page_limit: int | None = Form(default=None),
        x_correlation_id: str | None = Header(default=None),
    ) -> ParseResponse:
        correlation_id = x_correlation_id or str(uuid.uuid4())
        if page_limit is not None and not 1 <= page_limit <= settings.max_pages:
            raise HTTPException(status_code=422, detail="invalid page_limit")
        started = time.perf_counter()

        contents = await file.read(settings.max_pdf_bytes + 1)
        if len(contents) > settings.max_pdf_bytes:
            raise HTTPException(status_code=413, detail="payload too large")
        if not contents.lstrip()[:1024].startswith(b"%PDF-"):
            raise HTTPException(status_code=415, detail="unsupported media type")

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "document.pdf"
            path.write_bytes(contents)
            tracker.enqueue()
            token: int | None = None
            success = False
            try:
                async with limiter:
                    token = tracker.start(correlation_id[:120])
                    document = InputDocument(
                        path=path, max_pages=settings.max_pages, page_limit=page_limit,
                        on_page=lambda page, total, token=token: tracker.progress(token, page, total),
                    )
                    parsed = await run_in_threadpool(executor.parse, document)
                    success = True
            except EngineError:
                logger.warning("ocr_engine_error correlation_id=%s", correlation_id)
                raise HTTPException(status_code=422, detail="document could not be parsed")
            except WorkerCrashed as exc:
                logger.error("ocr_worker_crashed correlation_id=%s reason=%s", correlation_id, exc)
                raise HTTPException(status_code=504, detail="ocr worker interrupted")
            except Exception:
                logger.exception("ocr_unexpected_error correlation_id=%s", correlation_id)
                raise HTTPException(status_code=500, detail="internal error")
            finally:
                if token is None:
                    tracker.abandon_queue()
                else:
                    tracker.finish(token, success)

        duration_ms = int((time.perf_counter() - started) * 1000)
        logger.info(
            "ocr_completed correlation_id=%s engine=%s pages=%d duration_ms=%d",
            correlation_id, parsed.engine, len(parsed.pages), duration_ms,
        )
        return to_response(parsed, duration_ms)

    return app


def to_response(parsed: ParsedDocument, duration_ms: int) -> ParseResponse:
    pages = [
        PageModel(
            page=page.page,
            text=page.text if page.text is not None else blocks_to_text(page.blocks),
            confidence=page.confidence,
            blocks=[BlockModel(type=b.type, text=b.text, confidence=b.confidence, bbox=b.bbox) for b in page.blocks],
        )
        for page in sorted(parsed.pages, key=lambda p: p.page)
    ]

    return ParseResponse(
        engine=parsed.engine,
        engine_version=parsed.engine_version,
        pages=pages,
        processing=ProcessingModel(duration_ms=duration_ms),
    )


def get_app() -> FastAPI:
    return create_app()
