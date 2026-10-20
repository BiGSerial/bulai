import hmac
import logging
import tempfile
import time
import uuid
from pathlib import Path

from fastapi import Depends, FastAPI, File, Header, HTTPException, Request, UploadFile

from .config import Settings
from .engine import DocumentOcrEngine, EngineError, build_engine
from .layout import blocks_to_text
from .models import (
    BlockModel,
    InputDocument,
    PageModel,
    ParsedDocument,
    ParseResponse,
    ProcessingModel,
)

logger = logging.getLogger("document-ocr")


def create_app(settings: Settings | None = None, engine: DocumentOcrEngine | None = None) -> FastAPI:
    settings = settings or Settings.from_env()
    app = FastAPI(title="BULA-IA Document OCR", docs_url=None, redoc_url=None)
    state: dict[str, DocumentOcrEngine | None] = {"engine": engine}

    def get_engine() -> DocumentOcrEngine:
        if state["engine"] is None:
            state["engine"] = build_engine(settings)
        return state["engine"]

    def authorize(authorization: str | None = Header(default=None)) -> None:
        if settings.token is None:
            return
        expected = f"Bearer {settings.token}"
        if authorization is None or not hmac.compare_digest(authorization, expected):
            raise HTTPException(status_code=401, detail="unauthorized")

    @app.get("/health")
    def health() -> dict[str, str]:
        return {"status": "ok"}

    @app.post("/v1/documents/parse", response_model=ParseResponse, dependencies=[Depends(authorize)])
    async def parse(
        request: Request,
        file: UploadFile = File(...),
        x_correlation_id: str | None = Header(default=None),
    ) -> ParseResponse:
        correlation_id = x_correlation_id or str(uuid.uuid4())
        started = time.perf_counter()

        contents = await file.read(settings.max_pdf_bytes + 1)
        if len(contents) > settings.max_pdf_bytes:
            raise HTTPException(status_code=413, detail="payload too large")
        if not contents.lstrip()[:1024].startswith(b"%PDF-"):
            raise HTTPException(status_code=415, detail="unsupported media type")

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "document.pdf"
            path.write_bytes(contents)
            try:
                parsed = get_engine().parse(InputDocument(path=path, max_pages=settings.max_pages))
            except EngineError:
                logger.warning("ocr_engine_error correlation_id=%s", correlation_id)
                raise HTTPException(status_code=422, detail="document could not be parsed")
            except Exception:
                logger.exception("ocr_unexpected_error correlation_id=%s", correlation_id)
                raise HTTPException(status_code=500, detail="internal error")

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
