import json
import threading
from http.client import HTTPConnection
from http.server import ThreadingHTTPServer
from pathlib import Path

from app.supervisor import (ERROR, MODEL_LOADING, MODEL_NOT_INSTALLED, READY, Config, Supervisor,
                            make_handler)


class FakeProcess:
    def __init__(self):
        self.returncode = None

    def poll(self):
        return self.returncode

    def terminate(self):
        self.returncode = -15


def make(tmp_path: Path, probe, installed=True, **overrides):
    model = tmp_path / "model.gguf"
    if installed:
        model.write_bytes(b"gguf")
    config = Config(model_path=model, model_name="m", load_timeout=100, **overrides)
    processes = []
    supervisor = Supervisor(config, probe=probe, spawn=lambda cmd: processes.append(FakeProcess()) or processes[-1])
    return supervisor, processes, model


def test_missing_model_reports_model_not_installed_without_spawning(tmp_path):
    supervisor, processes, _ = make(tmp_path, lambda *a: (200, b""), installed=False)

    assert supervisor.tick().state == MODEL_NOT_INSTALLED
    assert processes == []


def test_model_appearing_later_starts_process_and_becomes_ready_only_after_probe(tmp_path):
    calls = []

    def probe(method, path, body):
        calls.append((method, path))
        if path == "/health":
            return (200, b"") if len(calls) >= 2 else (503, b"")
        return 200, b"{}"

    supervisor, processes, _ = make(tmp_path, probe)

    assert supervisor.tick(0).state == MODEL_LOADING  # spawn
    assert supervisor.tick(1).state == MODEL_LOADING  # upstream 503
    assert supervisor.tick(2).state == READY
    assert ("POST", "/v1/chat/completions") in calls
    assert len(processes) == 1


def test_loaded_model_that_cannot_answer_is_error_not_ready(tmp_path):
    supervisor, *_ = make(tmp_path, lambda m, p, b: (200, b"") if p == "/health" else (500, b""))
    supervisor.tick(0)

    assert supervisor.tick(1).state == ERROR


def test_load_timeout_and_crash_restart_with_backoff(tmp_path):
    supervisor, processes, _ = make(tmp_path, lambda *a: (503, b""))
    supervisor.tick(0)
    assert supervisor.tick(500).state == ERROR  # load timeout

    processes[0].returncode = 1
    assert supervisor.tick(501).state == ERROR
    assert len(processes) == 1  # backoff: no immediate respawn
    supervisor.tick(600)
    assert len(processes) == 2


def test_http_endpoints_health_status_and_restricted_proxy(tmp_path):
    supervisor, *_ = make(tmp_path, lambda *a: (503, b""), installed=False)
    supervisor.tick()
    server = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(supervisor))
    threading.Thread(target=server.serve_forever, daemon=True).start()
    try:
        def request(method, path, body=None):
            conn = HTTPConnection("127.0.0.1", server.server_address[1], timeout=5)
            conn.request(method, path, body=body)
            response = conn.getresponse()
            return response.status, json.loads(response.read())

        assert request("GET", "/health") == (503, {**request("GET", "/status")[1]})
        status, body = request("GET", "/status")
        assert status == 200 and body["status"] == MODEL_NOT_INSTALLED
        assert request("POST", "/v1/chat/completions", "{}")[0] == 503
        assert request("GET", "/props")[0] == 404
    finally:
        server.shutdown()
