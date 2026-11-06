import time

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


def test_page_limit_is_forwarded_to_the_engine_and_validated():
    seen = {}

    class LimitEngine(FakeEngine):
        def parse(self, document: InputDocument) -> ParsedDocument:
            seen["limit"] = document.page_limit
            return super().parse(document)

    c = client(LimitEngine())
    assert c.post("/v1/documents/parse", files={"file": ("a.pdf", PDF, "application/pdf")}, data={"page_limit": "3"}).status_code == 200
    assert seen["limit"] == 3
    assert c.post("/v1/documents/parse", files={"file": ("a.pdf", PDF, "application/pdf")}).status_code == 200
    assert seen["limit"] is None
    for invalid in ("0", "-1", "999"):
        assert c.post("/v1/documents/parse", files={"file": ("a.pdf", PDF, "application/pdf")}, data={"page_limit": invalid}).status_code == 422


def test_settings_default_to_light_models_without_mkldnn(monkeypatch):
    for name in ("OCR_DET_MODEL", "OCR_REC_MODEL", "OCR_ENABLE_MKLDNN", "OCR_CPU_THREADS", "OCR_RENDER_DPI"):
        monkeypatch.delenv(name, raising=False)
    loaded = Settings.from_env()
    assert (loaded.det_model, loaded.rec_model) == ("PP-OCRv5_mobile_det", "latin_PP-OCRv5_mobile_rec")
    assert loaded.enable_mkldnn is False and loaded.cpu_threads == 2 and loaded.render_dpi == 150
    monkeypatch.setenv("OCR_ENABLE_MKLDNN", "true")
    assert Settings.from_env().enable_mkldnn is True


def test_status_idle_has_the_contract_fields():
    body = client().get("/status").json()

    assert set(body) == {"status", "busy", "active", "queued", "current_seconds", "since", "completed", "failed", "uptime_seconds", "current"}
    assert body["status"] == "ok" and body["busy"] is False and body["current"] is None
    assert (body["active"], body["queued"], body["completed"], body["failed"]) == (0, 0, 0, 0)
    assert body["current_seconds"] is None and body["since"] is None
    assert body["uptime_seconds"] >= 0


def test_status_is_busy_while_processing_and_counts_queue_and_completion():
    import asyncio
    import threading
    import time

    import httpx

    class SlowEngine(FakeEngine):
        def parse(self, document: InputDocument) -> ParsedDocument:
            time.sleep(0.4)
            return super().parse(document)

    app = create_app(settings(), SlowEngine())

    async def scenario():
        async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url="http://ocr") as http:
            parses = [
                asyncio.create_task(http.post("/v1/documents/parse", files={"file": ("a.pdf", PDF, "application/pdf")}))
                for _ in range(2)
            ]
            await asyncio.sleep(0.15)
            busy = (await http.get("/status")).json()
            await asyncio.gather(*parses)
            done = (await http.get("/status")).json()
            return busy, done

    busy, done = asyncio.run(scenario())

    assert busy["busy"] is True and busy["active"] == 1 and busy["queued"] == 1
    assert busy["current_seconds"] > 0 and busy["since"].endswith("Z")
    assert done["busy"] is False and done["active"] == 0 and done["queued"] == 0
    assert done["completed"] == 2 and done["failed"] == 0 and done["since"] is None


def test_status_counts_failures():
    c = client(FakeEngine(EngineError("x")))
    post(c)
    body = c.get("/status").json()

    assert body["failed"] == 1 and body["completed"] == 0 and body["active"] == 0 and body["queued"] == 0


def wait_for(condition, timeout=2.0):
    import time

    deadline = time.time() + timeout
    while time.time() < deadline:
        if condition():
            return True
        time.sleep(0.01)
    return False


def test_admin_restart_requires_the_token_and_exits_after_the_response():
    exits = []
    app = create_app(settings(token="s3cret"), FakeEngine(), exit_fn=exits.append, restart_delay=0.05)
    c = TestClient(app)

    assert c.post("/admin/restart").status_code == 401
    assert c.post("/admin/restart", headers={"Authorization": "Bearer wrong"}).status_code == 401
    assert exits == []

    response = c.post("/admin/restart", headers={"Authorization": "Bearer s3cret"})
    assert response.status_code == 202 and response.json() == {"status": "restarting"}
    assert wait_for(lambda: exits == [0])


def test_admin_restart_is_404_without_a_configured_token():
    exits = []
    c = TestClient(create_app(settings(token=None), FakeEngine(), exit_fn=exits.append, restart_delay=0.01))

    assert c.post("/admin/restart").status_code == 404
    assert c.post("/admin/restart", headers={"Authorization": "Bearer anything"}).status_code == 404
    assert not wait_for(lambda: exits, timeout=0.2)


def test_watchdog_exits_with_code_3_only_when_the_oldest_document_exceeds_the_timeout():
    from app.runtime import ActivityTracker, Watchdog

    now = [100.0]
    exits = []
    tracker = ActivityTracker(clock=lambda: now[0])
    watchdog = Watchdog(tracker, timeout=60, exit_fn=exits.append, interval=0.01)

    assert watchdog.check() is False  # ocioso
    tracker.enqueue()
    token = tracker.start()
    now[0] += 60
    assert watchdog.check() is False and exits == []
    now[0] += 1
    assert watchdog.check() is True and exits == [3]

    tracker.finish(token, success=False)
    assert Watchdog(tracker, timeout=0, exit_fn=exits.append).check() is False


def test_watchdog_thread_fires_exit_fn():
    from app.runtime import ActivityTracker, Watchdog

    now = [0.0]
    exits = []
    tracker = ActivityTracker(clock=lambda: now[0])
    tracker.enqueue()
    tracker.start()
    now[0] = 10.0
    watchdog = Watchdog(tracker, timeout=5, exit_fn=exits.append, interval=0.01)
    watchdog.start()

    assert wait_for(lambda: exits == [3])
    watchdog.stop()


def test_request_timeout_setting(monkeypatch):
    monkeypatch.delenv("OCR_REQUEST_TIMEOUT", raising=False)
    assert Settings.from_env().request_timeout == 900
    monkeypatch.setenv("OCR_REQUEST_TIMEOUT", "0")
    assert Settings.from_env().request_timeout == 0


# --- processo filho (OCR_EXECUTOR=process) -----------------------------------------------------------------------


def process_client(engine_path: str, monkeypatch, request_timeout: int = 0, **env) -> TestClient:
    """Servidor com o engine num processo filho real (spawn); `tests.fake_engine` é importável pelo filho via pythonpath."""
    for name, value in env.items():
        monkeypatch.setenv(name, value)
    app = create_app(settings(engine=engine_path, request_timeout=request_timeout), executor_mode="process", watchdog_interval=0.05)
    return TestClient(app, raise_server_exceptions=False)


def test_process_executor_reports_page_progress_in_status_and_keeps_status_fast(monkeypatch):
    import threading

    with process_client("tests.fake_engine:PagedFakeEngine", monkeypatch, FAKE_PAGES="4", FAKE_PAGE_SECONDS="0.25") as c:
        assert c.get("/status").json()["worker"]["alive"] is True
        seen = []
        latencies = []

        def observe():
            while True:
                started = time.perf_counter()
                body = c.get("/status").json()
                latencies.append(time.perf_counter() - started)
                seen.append(body["current"])
                if not body["busy"] and len(seen) > 3:
                    return
                time.sleep(0.05)

        watcher = threading.Thread(target=observe)
        watcher.start()
        response = post(c, headers={"X-Correlation-Id": "doc-42"})
        watcher.join(timeout=5)

        assert response.status_code == 200
        assert [p["page"] for p in response.json()["pages"]] == [1, 2, 3, 4]
        pages = [s["page"] for s in seen if s is not None]
        assert pages == sorted(pages) and pages[-1] >= 3  # progresso monotônico até quase o fim
        assert all(s["correlation_id"] == "doc-42" for s in seen if s is not None)
        busy = next(s for s in seen if s is not None and s["total_pages"] is not None)
        assert busy["total_pages"] == 4 and busy["started_at"].endswith("Z")
        assert busy["elapsed_seconds"] >= 0
        assert seen[-1] is None  # volta a null no fim
        assert max(latencies) < 0.2
        done = c.get("/status").json()
        assert done["completed"] == 1 and done["failed"] == 0 and done["worker"]["restarts"] == 0


def test_process_executor_engine_errors_are_422_and_the_child_survives(monkeypatch):
    with process_client("tests.fake_engine:FailingFakeEngine", monkeypatch) as c:
        assert post(c).status_code == 422
        assert post(c).status_code == 422
        body = c.get("/status").json()
        assert body["failed"] == 2 and body["worker"]["alive"] is True and body["worker"]["restarts"] == 0


def test_process_executor_recovers_when_the_child_dies_mid_document(monkeypatch):
    with process_client("tests.fake_engine:CrashingFakeEngine", monkeypatch) as c:
        first = post(c)
        assert first.status_code == 504
        after_crash = c.get("/status").json()
        assert after_crash["busy"] is False and after_crash["failed"] == 1 and after_crash["worker"]["alive"] is False

        # O próximo pedido recria o filho sem reiniciar o servidor.
        monkeypatch.setenv("FAKE_PAGES", "1")
        monkeypatch.setenv("FAKE_PAGE_SECONDS", "0")
        c.app.state.executor._settings = settings(engine="tests.fake_engine:PagedFakeEngine")
        second = post(c)
        assert second.status_code == 200
        body = c.get("/status").json()
        assert body["worker"]["alive"] is True and body["worker"]["restarts"] == 1 and body["completed"] == 1


def test_watchdog_kills_the_child_and_the_request_gets_504_instead_of_exiting(monkeypatch):
    exits = []
    monkeypatch.setenv("FAKE_PAGES", "50")
    monkeypatch.setenv("FAKE_PAGE_SECONDS", "0.2")
    app = create_app(settings(engine="tests.fake_engine:PagedFakeEngine", request_timeout=1), exit_fn=exits.append, watchdog_interval=0.05, executor_mode="process")
    with TestClient(app, raise_server_exceptions=False) as c:
        started = time.perf_counter()
        response = post(c)
        assert response.status_code == 504 and time.perf_counter() - started < 8
        assert exits == []  # o servidor continua de pé
        assert c.get("/health").status_code == 200
        assert c.get("/status").json()["busy"] is False


def test_watchdog_on_timeout_hook_replaces_exit():
    from app.runtime import ActivityTracker, Watchdog

    now = [0.0]
    exits, kills = [], []
    tracker = ActivityTracker(clock=lambda: now[0])
    tracker.enqueue()
    tracker.start("abc")
    now[0] = 10.0
    watchdog = Watchdog(tracker, timeout=5, exit_fn=exits.append, on_timeout=lambda: kills.append(True))

    assert watchdog.check() is True and kills == [True] and exits == []


def test_tracker_progress_and_current_snapshot():
    from app.runtime import ActivityTracker

    now = [100.0]
    tracker = ActivityTracker(clock=lambda: now[0], wall_clock=lambda: 1_700_000_000.0)
    assert tracker.snapshot()["current"] is None
    tracker.enqueue()
    token = tracker.start("corr-1")
    tracker.progress(token, 3, 12)
    now[0] = 165.5
    current = tracker.snapshot()["current"]

    assert current == {"correlation_id": "corr-1", "page": 3, "total_pages": 12, "started_at": "2023-11-14T22:13:20Z", "elapsed_seconds": 65.5}
    tracker.finish(token, success=True)
    assert tracker.snapshot()["current"] is None
