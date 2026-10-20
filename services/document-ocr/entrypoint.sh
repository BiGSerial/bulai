#!/bin/sh
set -eu
exec uvicorn app.main:get_app --factory --host 0.0.0.0 --port "${OCR_PORT:-8000}"
