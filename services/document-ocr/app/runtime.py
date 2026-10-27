"""Estado de execução em memória do OCR (ocupação, contadores) e watchdog de travamento."""
import logging
import threading
import time
from datetime import datetime, timezone
from typing import Callable

logger = logging.getLogger("document-ocr")


class ActivityTracker:
    """Conta documentos aguardando, em processamento, concluídos e com falha. Thread-safe."""

    def __init__(self, clock: Callable[[], float] = time.monotonic, wall_clock: Callable[[], float] = time.time) -> None:
        self._clock = clock
        self._wall_clock = wall_clock
        self._lock = threading.Lock()
        self._active: dict[int, tuple[float, float]] = {}
        self._next_id = 0
        self._queued = 0
        self._completed = 0
        self._failed = 0
        self._booted = clock()

    def enqueue(self) -> None:
        with self._lock:
            self._queued += 1

    def start(self) -> int:
        """Move um documento da fila para o processamento e devolve seu identificador."""
        with self._lock:
            self._queued = max(0, self._queued - 1)
            self._next_id += 1
            self._active[self._next_id] = (self._clock(), self._wall_clock())
            return self._next_id

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
            return self._clock() - min(started for started, _ in self._active.values())

    def snapshot(self) -> dict:
        with self._lock:
            now = self._clock()
            oldest = min(self._active.values(), default=None)
            since = None
            if oldest is not None:
                since = datetime.fromtimestamp(oldest[1], tz=timezone.utc).isoformat().replace("+00:00", "Z")
            return {
                "status": "ok",
                "busy": bool(self._active),
                "active": len(self._active),
                "queued": self._queued,
                "current_seconds": round(now - oldest[0], 3) if oldest is not None else None,
                "since": since,
                "completed": self._completed,
                "failed": self._failed,
                "uptime_seconds": round(now - self._booted, 3),
            }


class Watchdog:
    """Encerra o processo (código 3) quando o documento mais antigo excede o tempo limite; o Docker religa o container."""

    EXIT_CODE = 3

    def __init__(self, tracker: ActivityTracker, timeout: float, exit_fn: Callable[[int], object], interval: float = 5.0) -> None:
        self.tracker = tracker
        self.timeout = timeout
        self.exit_fn = exit_fn
        self.interval = interval
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None

    def check(self) -> bool:
        if self.timeout <= 0:
            return False
        oldest = self.tracker.oldest_seconds()
        if oldest is None or oldest <= self.timeout:
            return False
        logger.error("ocr_watchdog_timeout seconds=%.0f timeout=%.0f", oldest, self.timeout)
        self.exit_fn(self.EXIT_CODE)
        return True

    def run(self) -> None:
        while not self._stop.wait(self.interval):
            try:
                if self.check():
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
