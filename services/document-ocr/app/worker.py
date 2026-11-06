"""Execução do engine fora do processo web.

O PaddleOCR segura o GIL enquanto reconhece uma página; no mesmo processo do servidor, `/status` e `/health` passam a
demorar segundos. Aqui o engine vive num processo filho persistente (contexto `spawn`): o pai só espera mensagens numa
fila, recebe o progresso por página e devolve o resultado. Se o filho morrer ou travar, o pedido em curso falha de forma
controlada e o próximo pedido cria um filho novo.
"""
import logging
import multiprocessing
import os
import threading
from multiprocessing.context import SpawnContext
from pathlib import Path
from typing import Callable, Protocol

from .config import Settings
from .engine import DocumentOcrEngine, EngineError, build_engine
from .models import InputDocument, PageProgress, ParsedDocument

logger = logging.getLogger("document-ocr")


class WorkerCrashed(Exception):
    """O processo filho morreu (ou foi morto pelo watchdog) antes de responder; o pedido em curso é perdido."""


class Executor(Protocol):
    def parse(self, document: InputDocument) -> ParsedDocument: ...

    def start(self) -> None: ...

    def stop(self) -> None: ...

    def kill(self) -> None: ...


class InlineExecutor:
    """Engine no próprio processo (testes e engines injetados). Mantém o comportamento original."""

    def __init__(self, engine_factory: Callable[[], DocumentOcrEngine]) -> None:
        self._factory = engine_factory
        self._engine: DocumentOcrEngine | None = None

    def parse(self, document: InputDocument) -> ParsedDocument:
        if self._engine is None:
            self._engine = self._factory()
        return self._engine.parse(document)

    def start(self) -> None:
        return None

    def stop(self) -> None:
        return None

    def kill(self) -> None:
        return None


def _worker_main(settings: Settings, requests: multiprocessing.Queue, results: multiprocessing.Queue) -> None:
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(name)s %(message)s")
    try:
        engine = build_engine(settings)
        engine.warmup()
    except Exception:
        logger.exception("ocr_worker_boot_failed")
        results.put(("boot_failed", None))
        return
    results.put(("ready", None))
    while True:
        message = requests.get()
        if message is None:
            return
        request_id, path, max_pages, page_limit = message

        def on_page(page: int, total: int, request_id: int = request_id) -> None:
            results.put(("page", request_id, page, total))

        try:
            parsed = engine.parse(InputDocument(path=Path(path), max_pages=max_pages, page_limit=page_limit, on_page=on_page))
            results.put(("done", request_id, parsed))
        except EngineError as exc:
            results.put(("error", request_id, "engine", str(exc)))
        except Exception:
            logger.exception("ocr_worker_unexpected_error request_id=%s", request_id)
            results.put(("error", request_id, "unexpected", ""))


class ProcessExecutor:
    """Um processo filho de longa duração; um documento por vez (o engine é sequencial de qualquer forma)."""

    def __init__(self, settings: Settings, poll_seconds: float = 1.0, boot_timeout: float = 600.0) -> None:
        self._settings = settings
        self._poll = poll_seconds
        self._boot_timeout = boot_timeout
        self._ctx: SpawnContext = multiprocessing.get_context("spawn")
        self._lock = threading.Lock()  # serializa os pedidos ao filho
        self._state_lock = threading.Lock()  # protege process/queues durante kill()
        self._process: multiprocessing.Process | None = None
        self._requests: multiprocessing.Queue | None = None
        self._results: multiprocessing.Queue | None = None
        self._ready = False
        self._next_request_id = 0
        self.restarts = 0

    # --- ciclo de vida -------------------------------------------------------------------------------------------

    def start(self) -> None:
        with self._state_lock:
            if self._process is None or not self._process.is_alive():
                self._spawn_locked()

    def stop(self) -> None:
        with self._state_lock:
            process, requests = self._process, self._requests
            self._process, self._requests, self._results, self._ready = None, None, None, False
        if process is not None and process.is_alive():
            try:
                if requests is not None:
                    requests.put(None)
                process.join(timeout=3)
            finally:
                if process.is_alive():
                    process.kill()

    def kill(self) -> None:
        """Mata o filho (watchdog). O pedido em curso recebe WorkerCrashed; o próximo pedido cria outro filho."""
        with self._state_lock:
            process = self._process
            self._process, self._requests, self._results, self._ready = None, None, None, False
        if process is not None and process.is_alive():
            logger.error("ocr_worker_killed pid=%s", process.pid)
            process.kill()
            process.join(timeout=5)

    def alive(self) -> bool:
        with self._state_lock:
            return self._process is not None and self._process.is_alive()

    def _spawn_locked(self) -> None:
        if self._process is not None:
            self.restarts += 1
        self._requests = self._ctx.Queue()
        self._results = self._ctx.Queue()
        self._process = self._ctx.Process(target=_worker_main, args=(self._settings, self._requests, self._results), name="ocr-engine", daemon=True)
        self._process.start()
        self._ready = False
        logger.info("ocr_worker_started pid=%s restarts=%d", self._process.pid, self.restarts)

    # --- pedidos ---------------------------------------------------------------------------------------------------

    def parse(self, document: InputDocument) -> ParsedDocument:
        with self._lock:
            with self._state_lock:
                if self._process is None or not self._process.is_alive():
                    self._spawn_locked()
                process, requests, results = self._process, self._requests, self._results
                self._next_request_id += 1
                request_id = self._next_request_id
            assert process is not None and requests is not None and results is not None
            self._wait_ready(process, results)
            requests.put((request_id, str(document.path), document.max_pages, document.page_limit))
            return self._collect(request_id, process, results, document.on_page)

    def _wait_ready(self, process: multiprocessing.Process, results: multiprocessing.Queue) -> None:
        if self._ready:
            return
        waited = 0.0
        while True:
            try:
                message = results.get(timeout=self._poll)
            except Exception:  # queue.Empty
                if not process.is_alive():
                    raise WorkerCrashed("worker died while booting")
                waited += self._poll
                if waited >= self._boot_timeout:
                    self.kill()
                    raise WorkerCrashed("worker boot timeout")
                continue
            if message[0] == "ready":
                self._ready = True
                return
            if message[0] == "boot_failed":
                self.kill()
                raise WorkerCrashed("worker boot failed")

    def _collect(self, request_id: int, process: multiprocessing.Process, results: multiprocessing.Queue, on_page: PageProgress | None) -> ParsedDocument:
        while True:
            try:
                message = results.get(timeout=self._poll)
            except Exception:  # queue.Empty
                if not process.is_alive() or self._results is not results:
                    raise WorkerCrashed("worker died during parse")
                continue
            kind = message[0]
            if kind in ("ready", "boot_failed") or message[1] != request_id:
                continue  # resto de um pedido anterior
            if kind == "page":
                if on_page is not None:
                    on_page(message[2], message[3])
            elif kind == "done":
                return message[2]
            elif kind == "error":
                if message[2] == "engine":
                    raise EngineError(message[3])
                raise RuntimeError("engine failed in worker")


def build_executor(settings: Settings, engine: DocumentOcrEngine | None = None, mode: str | None = None) -> Executor:
    """`engine` injetado (testes) roda no próprio processo; caso contrário OCR_EXECUTOR decide (`process`, padrão, ou `inline`)."""
    mode = mode or os.getenv("OCR_EXECUTOR", "process")
    if engine is not None:
        return InlineExecutor(lambda: engine)
    if mode == "inline":
        return InlineExecutor(lambda: build_engine(settings))
    return ProcessExecutor(settings)
