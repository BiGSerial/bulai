from fastapi.testclient import TestClient

from app.config import Settings
from app.engine import DocumentOcrEngine, EngineError
from app.layout import blocks_to_text
from app.main import create_app
from app.models import Block, InputDocument, ParsedDocument, ParsedPage

PDF = b"%PDF-1.4\n%fake"


class FakeEngine(DocumentOcrEngine):
    def __init__(self, error: Exception | None = None) -> None:
        self.error = error
        self.calls = 0

    def parse(self, document: InputDocument) -> ParsedDocument:
        self.calls += 1
        if self.error:
            raise self.error
        return ParsedDocument(
            engine="fake",
            engine_version=None,
            pages=[
                ParsedPage(page=2, blocks=[Block(text="Segunda")]),
                ParsedPage(
                    page=1,
                    blocks=[Block(text="NOVALGINA", type="title", confidence=0.98, bbox=[120, 80, 620, 150])],
                    confidence=0.96,
                ),
            ],
        )


def settings(**overrides) -> Settings:
    base = dict(engine="fake", token=None, max_pdf_bytes=1024, max_pages=10, render_dpi=72, language="pt")
    return Settings(**{**base, **overrides})


def client(engine: DocumentOcrEngine | None = None, **overrides) -> TestClient:
    return TestClient(create_app(settings(**overrides), engine or FakeEngine()), raise_server_exceptions=False)


def post(c: TestClient, content: bytes = PDF, headers: dict | None = None):
    return c.post("/v1/documents/parse", files={"file": ("a.pdf", content, "application/pdf")}, headers=headers or {})


def test_health():
    assert client().get("/health").json() == {"status": "ok"}


def test_parse_returns_ordered_pages_without_inventing_fields():
    body = post(client()).json()

    assert body["engine"] == "fake"
    assert [p["page"] for p in body["pages"]] == [1, 2]
    first, second = body["pages"]
    assert first["text"] == "NOVALGINA"
    assert first["blocks"][0] == {"type": "title", "text": "NOVALGINA", "confidence": 0.98, "bbox": [120, 80, 620, 150]}
    assert second["confidence"] is None
    assert second["blocks"][0]["bbox"] is None and second["blocks"][0]["confidence"] is None
    assert body["processing"]["duration_ms"] >= 0


def test_rejects_non_pdf_and_oversized_payloads_without_calling_engine():
    engine = FakeEngine()
    c = client(engine)

    assert post(c, b"not a pdf").status_code == 415
    assert post(c, PDF + b"x" * 2048).status_code == 413
    assert engine.calls == 0


def test_engine_errors_are_controlled_and_do_not_leak_details():
    assert post(client(FakeEngine(EngineError("secret detail /tmp/x")))).status_code == 422
    response = post(client(FakeEngine(RuntimeError("secret detail"))))

    assert response.status_code == 500
    assert "secret" not in response.text


def test_token_is_required_when_configured():
    c = client(token="s3cret")

    assert post(c).status_code == 401
    assert post(c, headers={"Authorization": "Bearer wrong"}).status_code == 401
    assert post(c, headers={"Authorization": "Bearer s3cret"}).status_code == 200
    assert c.get("/health").status_code == 200


def test_blocks_to_text_uses_reading_order():
    blocks = [
        Block(text="mundo", bbox=[100, 10, 150, 30]),
        Block(text="linha 2", bbox=[0, 60, 80, 80]),
        Block(text="Olá", bbox=[0, 12, 50, 32]),
    ]

    assert blocks_to_text(blocks) == "Olá mundo\nlinha 2"


def test_ocr_runs_one_document_at_a_time_and_health_stays_responsive():
    import asyncio
    import threading
    import time

    import httpx

    class SlowEngine(FakeEngine):
        def __init__(self) -> None:
            super().__init__()
            self.running = 0
            self.peak = 0
            self.lock = threading.Lock()

        def parse(self, document: InputDocument) -> ParsedDocument:
            with self.lock:
                self.running += 1
                self.peak = max(self.peak, self.running)
            time.sleep(0.3)
            with self.lock:
                self.running -= 1
            return super().parse(document)

    engine = SlowEngine()
    app = create_app(settings(), engine)

    async def scenario():
        async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://ocr") as http:
            parses = [
                asyncio.create_task(http.post("/v1/documents/parse", files={"file": ("a.pdf", PDF, "application/pdf")}))
                for _ in range(3)
            ]
            await asyncio.sleep(0.1)
            started = time.perf_counter()
            health = await http.get("/health")
            latency = time.perf_counter() - started
            results = await asyncio.gather(*parses)
            return health.status_code, latency, [r.status_code for r in results]

    health_status, health_latency, statuses = asyncio.run(scenario())

    assert statuses == [200, 200, 200]
    assert engine.peak == 1
    assert health_status == 200
    assert health_latency < 0.25
