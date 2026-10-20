# Serviço OCR interno do BULA-IA. CPU-only: nenhuma dependência de GPU/CUDA é necessária.
FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    OMP_NUM_THREADS=2 \
    OCR_PORT=8000

RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 libglib2.0-0 libgl1 curl \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 10001 ocr

WORKDIR /srv
COPY pyproject.toml .
COPY app ./app
RUN pip install ".[paddle]"
COPY entrypoint.sh .
RUN chmod +x entrypoint.sh
USER ocr

EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/health || exit 1

ENTRYPOINT ["./entrypoint.sh"]
