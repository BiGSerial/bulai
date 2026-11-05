import json
import threading
from http.client import HTTPConnection
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from app.supervisor import (ALLOWED_PROXY, ERROR, MODEL_LOADING, MODEL_NOT_INSTALLED, READY, Config, Supervisor,
                            make_handler)


class FakeProcess:
    def __init__(self):
        self.returncode = None

    def poll(self):
        return self.returncode

    def terminate(self):
        self.returncode = -15

    def wait(self, timeout=None):
        return self.returncode

    def kill(self):
        self.returncode = -9


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

        health_status, health_body = request("GET", "/health")
        status, body = request("GET", "/status")
        assert health_status == 503 and health_body.items() <= body.items()
        assert status == 200 and body["status"] == MODEL_NOT_INSTALLED
        assert request("POST", "/v1/chat/completions", "{}")[0] == 503
        assert request("GET", "/props")[0] == 404
    finally:
        server.shutdown()


STATUS_FIELDS = {"status", "detail", "model", "since", "busy", "active_requests", "current_seconds", "completed", "failed",
                 "restarts", "uptime_seconds"}


def ready_supervisor(tmp_path, **overrides):
    supervisor, processes, model = make(tmp_path, lambda *a: (200, b"{}"), **overrides)
    supervisor.tick(0)
    assert supervisor.tick(1).state == READY
    return supervisor, processes


def test_status_dict_idle_and_busy(tmp_path):
    clock = [1000.0]
    supervisor, _, _ = make(tmp_path, lambda *a: (200, b"{}"))
    supervisor._clock = lambda: clock[0]
    supervisor._booted_at = 1000.0

    idle = supervisor.status_dict()
    assert set(idle) == STATUS_FIELDS
    assert (idle["busy"], idle["active_requests"], idle["current_seconds"]) == (False, 0, None)
    assert (idle["completed"], idle["failed"], idle["restarts"]) == (0, 0, 0)

    first = supervisor.begin_request()
    clock[0] += 5
    second = supervisor.begin_request()
    clock[0] += 2
    busy = supervisor.status_dict()
    assert busy["busy"] is True and busy["active_requests"] == 2
    assert busy["current_seconds"] == 7 and busy["uptime_seconds"] == 7

    supervisor.end_request(first, True)
    supervisor.end_request(second, False)
    done = supervisor.status_dict()
    assert (done["busy"], done["completed"], done["failed"], done["current_seconds"]) == (False, 1, 1, None)


def serve(supervisor):
    server = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(supervisor))
    threading.Thread(target=server.serve_forever, daemon=True).start()

    def request(method, path, headers=None, body=None):
        conn = HTTPConnection("127.0.0.1", server.server_address[1], timeout=5)
        conn.request(method, path, body=body, headers=headers or {})
        response = conn.getresponse()
        return response.status, json.loads(response.read())

    return server, request


def test_admin_restart_without_configured_token_is_404(tmp_path):
    supervisor, processes = ready_supervisor(tmp_path)
    server, request = serve(supervisor)
    try:
        assert request("POST", "/admin/restart")[0] == 404
        assert request("POST", "/admin/restart", {"Authorization": "Bearer x"})[0] == 404
        assert len(processes) == 1 and processes[0].returncode is None
    finally:
        server.shutdown()


def test_admin_restart_rejects_missing_or_wrong_token(tmp_path):
    supervisor, processes = ready_supervisor(tmp_path, admin_token="s3cret")
    server, request = serve(supervisor)
    try:
        assert request("POST", "/admin/restart")[0] == 401
        assert request("POST", "/admin/restart", {"Authorization": "Bearer wrong"})[0] == 401
        assert len(processes) == 1 and supervisor.status().state == READY
    finally:
        server.shutdown()


def test_admin_restart_restarts_only_the_subprocess_and_returns_to_ready(tmp_path):
    supervisor, processes = ready_supervisor(tmp_path, admin_token="s3cret")
    server, request = serve(supervisor)
    try:
        status, body = request("POST", "/admin/restart", {"Authorization": "Bearer s3cret"})
        assert (status, body) == (202, {"status": "restarting"})
        assert processes[0].returncode == -15 and len(processes) == 2
        assert supervisor.status().state == MODEL_LOADING
        assert request("GET", "/status")[1]["restarts"] == 1
        assert request("POST", "/v1/chat/completions", body="{}")[0] == 503

        supervisor.tick(2)
        assert supervisor.tick(3).state == READY
        assert request("GET", "/status")[1]["restarts"] == 1
    finally:
        server.shutdown()


def test_admin_restart_is_not_reachable_through_the_proxy(tmp_path):
    supervisor, _ = ready_supervisor(tmp_path, admin_token="s3cret")
    server, request = serve(supervisor)
    try:
        assert request("GET", "/admin/restart", {"Authorization": "Bearer s3cret"})[0] == 404
        assert ("POST", "/admin/restart") not in ALLOWED_PROXY
    finally:
        server.shutdown()


def test_watchdog_restarts_after_hang_timeout_and_ignores_restarted_requests(tmp_path):
    clock = [0.0]
    supervisor, processes = ready_supervisor(tmp_path, hang_timeout=100)
    supervisor._clock = lambda: clock[0]

    token = supervisor.begin_request()
    clock[0] = 100
    assert supervisor.check_hang() is False
    assert len(processes) == 1
    clock[0] = 101
    assert supervisor.check_hang() is True
    assert len(processes) == 2 and processes[0].returncode == -15
    assert supervisor.status().state == MODEL_LOADING and supervisor.status_dict()["restarts"] == 1

    clock[0] = 500  # a requisição antiga ainda não terminou, mas pertence ao processo já reiniciado
    assert supervisor.check_hang() is False
    supervisor.end_request(token, False)


def test_watchdog_disabled_with_zero_timeout(tmp_path):
    supervisor, processes = ready_supervisor(tmp_path, hang_timeout=0)
    supervisor.begin_request()

    assert supervisor.check_hang(now=10**9) is False
    assert len(processes) == 1


def test_proxy_counts_requests_in_flight_and_completed(tmp_path):
    release = threading.Event()
    entered = threading.Event()

    class Upstream(BaseHTTPRequestHandler):
        def do_POST(self):
            self.rfile.read(int(self.headers.get("Content-Length") or 0))
            entered.set()
            release.wait(5)
            body = b"{}"
            self.send_response(200)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *args):
            pass

    upstream = ThreadingHTTPServer(("127.0.0.1", 0), Upstream)
    threading.Thread(target=upstream.serve_forever, daemon=True).start()
    supervisor, _ = ready_supervisor(tmp_path, upstream_port=upstream.server_address[1])
    server, request = serve(supervisor)
    result = []
    try:
        worker = threading.Thread(target=lambda: result.append(request("POST", "/v1/chat/completions", body="{}")))
        worker.start()
        assert entered.wait(5)
        busy = request("GET", "/status")[1]
        assert busy["busy"] is True and busy["active_requests"] == 1 and busy["current_seconds"] >= 0

        release.set()
        worker.join(5)
        done = request("GET", "/status")[1]
        assert result[0][0] == 200
        assert (done["busy"], done["active_requests"], done["completed"], done["failed"]) == (False, 0, 1, 0)
    finally:
        release.set()
        server.shutdown()
        upstream.shutdown()


def test_config_reads_admin_token_and_hang_timeout():
    config = Config.from_env({"LOCAL_AI_ADMIN_TOKEN": "t", "LOCAL_AI_HANG_TIMEOUT": "30"})
    assert (config.admin_token, config.hang_timeout) == ("t", 30)
    default = Config.from_env({})
    assert (default.admin_token, default.hang_timeout) == (None, 240)


def test_embedding_mode_runs_llamafiler_probes_v1_models_and_restricts_proxy(tmp_path):
    calls = []

    def probe(method, path, body):
        calls.append((method, path, body))
        return 200, b"{}"

    supervisor, processes, _ = make(tmp_path, probe, mode="embedding")
    command = supervisor.command()
    assert "--v2" in command and "-l" in command and "--nobrowser" not in command
    assert "--pooling" not in command  # o llamafiler rejeita a flag; o pooling vem do GGUF
    assert "--embedding" not in command  # dispararia a CLI de embeddings, não um servidor

    supervisor.tick(0)
    assert supervisor.tick(1).state == READY
    assert ("GET", "/v1/models", None) in calls
    assert any(m == "POST" and p == "/v1/embeddings" and b["input"] == "ok" for m, p, b in calls)
    assert all(p != "/health" for _, p, _ in calls)
    assert supervisor.config.allowed_proxy == {("POST", "/v1/embeddings"), ("GET", "/v1/models")}
    assert ("POST", "/v1/chat/completions") not in supervisor.config.allowed_proxy
    assert len(processes) == 1


def test_embedding_mode_reports_its_own_installer_when_model_is_missing(tmp_path):
    supervisor, _, _ = make(tmp_path, lambda *a: (200, b""), installed=False, mode="embedding")
    assert supervisor.tick().detail == "run scripts/install-embedding-model.sh"


def test_config_from_env_reads_mode_and_rejects_unknown_mode():
    config = Config.from_env({"LOCAL_AI_MODE": "embedding"})
    assert config.is_embedding and config.max_body_bytes == 1048576
    assert Config.from_env({}).mode == "chat" and Config.from_env({}).max_body_bytes == 262144
    import pytest
    with pytest.raises(ValueError):
        Config.from_env({"LOCAL_AI_MODE": "vision"})


def test_embed_batch_fans_out_list_inputs_and_merges_openai_response(tmp_path):
    from app.supervisor import embed_batch

    config = Config(model_path=tmp_path / "m.gguf", model_name="bge-m3", mode="embedding")
    seen = []

    def upstream(body):
        payload = json.loads(body)
        assert isinstance(payload["input"], str)
        seen.append(payload["input"])
        return 200, json.dumps({"model": "bge-m3-Q8_0", "data": [{"index": 0, "embedding": [len(payload["input"]), 1.0]}],
                                "usage": {"prompt_tokens": 3}}).encode()

    status, raw = embed_batch(config, json.dumps({"model": "bge-m3", "input": ["ab", "abcd"]}).encode(), upstream)
    body = json.loads(raw)
    assert status == 200 and seen == ["ab", "abcd"]
    assert [d["index"] for d in body["data"]] == [0, 1] and body["data"][1]["embedding"] == [4, 1.0]
    assert body["model"] == "bge-m3" and body["upstream_model"] == "bge-m3-Q8_0" and body["usage"]["prompt_tokens"] == 6

    status, raw = embed_batch(config, json.dumps({"input": "solo"}).encode(), upstream)
    assert status == 200 and len(json.loads(raw)["data"]) == 1
    assert embed_batch(config, b"{}", upstream)[0] == 400
    assert embed_batch(config, json.dumps({"input": ["ok", ""]}).encode(), upstream)[0] == 400
    assert embed_batch(config, b"not json", upstream)[0] == 400
    assert embed_batch(config, json.dumps({"input": "x"}).encode(), lambda b: (500, b"boom"))[0] == 502
    assert embed_batch(config, json.dumps({"input": "x"}).encode(), lambda b: (200, b"{}"))[0] == 502


def test_embedding_proxy_merges_batches_and_counts_requests(tmp_path):
    from unittest import mock

    supervisor, _ = ready_supervisor(tmp_path, mode="embedding")
    server = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(supervisor))
    threading.Thread(target=server.serve_forever, daemon=True).start()
    try:
        def request(method, path, body=None):
            conn = HTTPConnection("127.0.0.1", server.server_address[1], timeout=5)
            conn.request(method, path, body=body)
            response = conn.getresponse()
            return response.status, json.loads(response.read())

        def fake_embed_batch(config, body, upstream=None):
            count = len(json.loads(body)["input"])
            return 200, json.dumps({"object": "list", "data": [{"index": i, "embedding": [0.5]} for i in range(count)]}).encode()

        with mock.patch("app.supervisor.embed_batch", side_effect=fake_embed_batch):
            status, body = request("POST", "/v1/embeddings", json.dumps({"input": ["a", "b", "c"]}))
        assert status == 200 and len(body["data"]) == 3
        assert request("POST", "/v1/chat/completions", "{}")[0] == 404
        assert supervisor.status_dict()["completed"] == 1
    finally:
        server.shutdown()
