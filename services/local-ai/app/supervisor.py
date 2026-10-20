"""Supervisor do llamafile: gerencia o processo, expõe o estado real do modelo e faz proxy restrito da API.

Somente biblioteca padrão. O container nunca entra em crash loop por falta de modelo: ele reporta
MODEL_NOT_INSTALLED e passa a iniciar o llamafile assim que o arquivo aparecer no volume.
"""
import http.client
import json
import logging
import os
import subprocess
import threading
import time
from dataclasses import dataclass, field
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Callable

log = logging.getLogger("local-ai")

SERVICE_AVAILABLE = "SERVICE_AVAILABLE"
MODEL_NOT_INSTALLED = "MODEL_NOT_INSTALLED"
MODEL_LOADING = "MODEL_LOADING"
READY = "READY"
ERROR = "ERROR"

ALLOWED_PROXY = {("POST", "/v1/chat/completions"), ("GET", "/v1/models")}


@dataclass(frozen=True)
class Config:
    model_path: Path
    model_name: str
    llamafile_bin: str = "/opt/llamafile/llamafile"
    host: str = "0.0.0.0"
    port: int = 8080
    upstream_port: int = 8081
    threads: int = 4
    context: int = 2048
    load_timeout: int = 600
    request_timeout: int = 120
    max_body_bytes: int = 256 * 1024

    @staticmethod
    def from_env(env: dict[str, str] | None = None) -> "Config":
        env = env if env is not None else dict(os.environ)
        directory = env.get("LOCAL_AI_MODEL_PATH", "/models")
        filename = env.get("LOCAL_AI_MODEL_FILENAME", "model.gguf")
        return Config(
            model_path=Path(directory) / filename,
            model_name=env.get("LOCAL_AI_MODEL_NAME", "local"),
            llamafile_bin=env.get("LOCAL_AI_LLAMAFILE_BIN", "/opt/llamafile/llamafile"),
            port=int(env.get("LOCAL_AI_PORT", "8080")),
            upstream_port=int(env.get("LOCAL_AI_UPSTREAM_PORT", "8081")),
            threads=int(env.get("LOCAL_AI_THREADS", "4")),
            context=int(env.get("LOCAL_AI_CONTEXT", "2048")),
            load_timeout=int(env.get("LOCAL_AI_LOAD_TIMEOUT", "600")),
            request_timeout=int(env.get("LOCAL_AI_REQUEST_TIMEOUT", "120")),
        )


@dataclass
class Status:
    state: str
    detail: str | None = None
    model: str | None = None
    since: float = field(default_factory=time.time)

    def as_dict(self) -> dict:
        return {"status": self.state, "detail": self.detail, "model": self.model, "since": int(self.since)}


Probe = Callable[[str, str, dict | None], tuple[int, bytes]]


def http_probe(port: int, timeout: float = 5.0) -> Probe:
    def call(method: str, path: str, body: dict | None) -> tuple[int, bytes]:
        conn = http.client.HTTPConnection("127.0.0.1", port, timeout=timeout)
        try:
            payload = json.dumps(body).encode() if body is not None else None
            conn.request(method, path, body=payload, headers={"Content-Type": "application/json"})
            response = conn.getresponse()
            return response.status, response.read()
        finally:
            conn.close()

    return call


class Supervisor:
    def __init__(self, config: Config, probe: Probe | None = None, spawn: Callable[[list[str]], subprocess.Popen] | None = None) -> None:
        self.config = config
        self._probe = probe or http_probe(config.upstream_port)
        self._spawn = spawn or (lambda cmd: subprocess.Popen(cmd))
        self._process: subprocess.Popen | None = None
        self._started_at: float | None = None
        self._restarts = 0
        self._next_restart_at = 0.0
        self._probe_ok = False
        self._lock = threading.Lock()
        self._status = Status(SERVICE_AVAILABLE, model=config.model_name)

    def command(self) -> list[str]:
        c = self.config
        return ["sh", c.llamafile_bin, "--server", "--nobrowser", "--host", "127.0.0.1", "--port", str(c.upstream_port),
                "-m", str(c.model_path), "-c", str(c.context), "-t", str(c.threads)]

    def status(self) -> Status:
        with self._lock:
            return self._status

    def _set(self, state: str, detail: str | None = None) -> None:
        if self._status.state != state or self._status.detail != detail:
            log.info("state=%s detail=%s", state, detail)
            self._status = Status(state, detail, self.config.model_name)

    def tick(self, now: float | None = None) -> Status:
        """Avança a máquina de estados uma vez (chamado periodicamente e nos testes)."""
        now = time.time() if now is None else now
        with self._lock:
            if not self.config.model_path.is_file():
                self._terminate()
                self._probe_ok = False
                self._set(MODEL_NOT_INSTALLED, "run scripts/install-local-model.sh")
                return self._status

            if self._process is not None and self._process.poll() is not None:
                code = self._process.returncode
                self._process = None
                self._probe_ok = False
                self._restarts += 1
                self._next_restart_at = now + min(60, 2 ** min(self._restarts, 6))
                self._set(ERROR, f"llamafile exited with code {code}")

            if self._process is None:
                if now < self._next_restart_at:
                    return self._status
                self._process = self._spawn(self.command())
                self._started_at = now
                self._probe_ok = False
                self._set(MODEL_LOADING, "starting llamafile")
                return self._status

            self._check_upstream(now)
            return self._status

    def _check_upstream(self, now: float) -> None:
        try:
            code, _ = self._probe("GET", "/health", None)
        except OSError:
            code = None

        if code == 200:
            if not self._probe_ok:
                self._probe_ok = self._inference_probe()
            if self._probe_ok:
                self._restarts = 0
                self._set(READY)
            else:
                self._set(ERROR, "model loaded but inference probe failed")
            return

        waited = now - (now if self._started_at is None else self._started_at)
        if waited > self.config.load_timeout:
            self._set(ERROR, "model load timeout")
        else:
            self._set(MODEL_LOADING, "loading model")

    def _inference_probe(self) -> bool:
        try:
            code, _ = self._probe("POST", "/v1/chat/completions", {
                "model": self.config.model_name, "max_tokens": 1, "temperature": 0,
                "messages": [{"role": "user", "content": "ok"}],
            })
            return code == 200
        except OSError:
            return False

    def _terminate(self) -> None:
        if self._process is not None and self._process.poll() is None:
            self._process.terminate()
        self._process = None

    def run_forever(self, interval: float = 3.0) -> None:
        while True:
            try:
                self.tick()
            except Exception:  # o supervisor nunca deve morrer
                log.exception("supervisor tick failed")
            time.sleep(interval)

    def shutdown(self) -> None:
        with self._lock:
            self._terminate()


def make_handler(supervisor: Supervisor) -> type[BaseHTTPRequestHandler]:
    config = supervisor.config

    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, fmt, *args):  # nunca registrar corpo de requisições
            log.info("%s %s", self.command, self.path.split("?")[0])

        def _send(self, code: int, body: bytes, content_type: str = "application/json") -> None:
            self.send_response(code)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def _json(self, code: int, payload: dict) -> None:
            self._send(code, json.dumps(payload).encode())

        def do_GET(self):
            path = self.path.split("?")[0]
            if path == "/status":
                return self._json(200, supervisor.status().as_dict())
            if path == "/health":
                status = supervisor.status()
                return self._json(200 if status.state == READY else 503, status.as_dict())
            self._proxy("GET", path)

        def do_POST(self):
            self._proxy("POST", self.path.split("?")[0])

        def _proxy(self, method: str, path: str) -> None:
            if (method, path) not in ALLOWED_PROXY:
                return self._json(404, {"error": "not_found"})
            status = supervisor.status()
            if status.state != READY:
                return self._json(503, {"error": "MODEL_NOT_READY", **status.as_dict()})
            length = int(self.headers.get("Content-Length") or 0)
            if length > config.max_body_bytes:
                return self._json(413, {"error": "payload_too_large"})
            body = self.rfile.read(length) if length else None
            try:
                conn = http.client.HTTPConnection("127.0.0.1", config.upstream_port, timeout=config.request_timeout)
                conn.request(method, path, body=body, headers={"Content-Type": "application/json"})
                response = conn.getresponse()
                self._send(response.status, response.read(), response.getheader("Content-Type") or "application/json")
                conn.close()
            except (OSError, http.client.HTTPException):
                self._json(504, {"error": "upstream_timeout_or_unavailable"})

    return Handler


def serve(config: Config) -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    supervisor = Supervisor(config)
    threading.Thread(target=supervisor.run_forever, daemon=True).start()
    server = ThreadingHTTPServer((config.host, config.port), make_handler(supervisor))
    log.info("local-ai supervisor listening on %s:%d", config.host, config.port)
    try:
        server.serve_forever()
    finally:
        supervisor.shutdown()


if __name__ == "__main__":
    serve(Config.from_env())
