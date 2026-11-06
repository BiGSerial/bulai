"""Estado de execução em memória do OCR (ocupação, progresso, contadores) e watchdog de travamento."""
import logging
import threading
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Callable

logger = logging.getLogger("document-ocr")


@dataclass
class _Active:
    started: float  # relógio monotônico
    started_wall: float
    correlation_id: str
    page: int = 0
    total_pages: int | None = None


class ActivityTracker:
    """Conta documentos aguardando, em processamento, concluídos e com falha, e guarda o progresso por página. Thread-safe."""

    def __init__(self, clock: Callable[[], float] = time.monotonic, wall_clock: Callable[[], float] = time.time) -> None:
        self._clock = clock
        self._wall_clock = wall_clock
        self._lock = threading.Lock()
        self._active: dict[int, _Active] = {}
        self._next_id = 0
        self._queued = 0
        self._completed = 0
        self._failed = 0
        self._booted = clock()

    def enqueue(self) -> None:
        with self._lock:
            self._queued += 1

    def start(self, correlation_id: str = "") -> int:
        """Move um documento da fila para o processamento e devolve seu identificador."""
        with self._lock:
            self._queued = max(0, self._queued - 1)
            self._next_id += 1
            self._active[self._next_id] = _Active(self._clock(), self._wall_clock(), correlation_id)
            return self._next_id

    def progress(self, token: int, page: int, total_pages: int) -> None:
        """Registra a última página concluída (0 = ainda nenhuma) e o total do documento."""
        with self._lock:
            active = self._active.get(token)
            if active is not None:
                active.page = max(0, page)
                active.total_pages = max(0, total_pages)

    def abandon_queue(self) -> None:
        with self._lock:
            self._queued = max(0, self._queued - 1)

    def finish(self, token: int, success: bool) -> None:
        with self._lock:
            self._active.pop(token, None)
            if success:
                self._completed += 1
            else:
                self._failed += 1

    def oldest_seconds(self) -> float | None:
        with self._lock:
            if not self._active:
                return None
            return self._clock() - min(active.started for active in self._active.values())

    def snapshot(self) -> dict:
        with self._lock:
            now = self._clock()
            oldest = min(self._active.values(), key=lambda active: active.started, default=None)
            since = None
            current = None
            if oldest is not None:
                since = _iso(oldest.started_wall)
                current = {
                    "correlation_id": oldest.correlation_id,
                    "page": oldest.page,
                    "total_pages": oldest.total_pages,
                    "started_at": since,
                    "elapsed_seconds": round(now - oldest.started, 3),
                }
            return {
                "status": "ok",
                "busy": bool(self._active),
                "active": len(self._active),
                "queued": self._queued,
                "current_seconds": round(now - oldest.started, 3) if oldest is not None else None,
                "since": since,
                "completed": self._completed,
                "failed": self._failed,
                "uptime_seconds": round(now - self._booted, 3),
                "current": current,
            }


def _iso(timestamp: float) -> str:
    return datetime.fromtimestamp(timestamp, tz=timezone.utc).isoformat().replace("+00:00", "Z")


class Watchdog:
    """Quando o documento mais antigo excede o tempo limite: com `on_timeout` (modo processo filho) mata o filho e o pedido
    em curso recebe 504; sem ele, encerra o processo (código 3) e o Docker religa o container."""

    EXIT_CODE = 3

    def __init__(
        self,
        tracker: ActivityTracker,
        timeout: float,
        exit_fn: Callable[[int], object],
        interval: float = 5.0,
        on_timeout: Callable[[], object] | None = None,
    ) -> None:
        self.tracker = tracker
        self.timeout = timeout
        self.exit_fn = exit_fn
        self.interval = interval
        self.on_timeout = on_timeout
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None

    def check(self) -> bool:
        if self.timeout <= 0:
            return False
        oldest = self.tracker.oldest_seconds()
        if oldest is None or oldest <= self.timeout:
            return False
        logger.error("ocr_watchdog_timeout seconds=%.0f timeout=%.0f", oldest, self.timeout)
        if self.on_timeout is not None:
            self.on_timeout()
        else:
            self.exit_fn(self.EXIT_CODE)
        return True

    def run(self) -> None:
        while not self._stop.wait(self.interval):
            try:
                if self.check() and self.on_timeout is None:
                    return
            except Exception:
                logger.exception("ocr_watchdog_failed")

    def start(self) -> None:
        if self.timeout <= 0 or self._thread is not None:
            return
        self._thread = threading.Thread(target=self.run, name="ocr-watchdog", daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()
