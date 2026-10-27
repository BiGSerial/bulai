# Serviço OCR interno do BULA-IA. CPU-only: nenhuma dependência de GPU/CUDA é necessária.
FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    OMP_NUM_THREADS=2 \
    OCR_MAX_CONCURRENCY=1 \
    OCR_ENABLE_MKLDNN=0 \
    OCR_PORT=8000

RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 libglib2.0-0 libgl1 curl \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 10001 ocr

WORKDIR /srv
ENV PYTHONPATH=/srv

# Dependências primeiro (camada pesada em cache: mudar o código do serviço não reinstala o Paddle).
# USE_CONSTRAINTS=1 (padrão) usa as versões exatas validadas em constraints.txt; o canário semanal usa 0 para testar versões novas.
ARG USE_CONSTRAINTS=1
COPY pyproject.toml constraints.txt ./
RUN python -c "import tomllib; p = tomllib.load(open('pyproject.toml', 'rb'))['project']; print('\n'.join(p['dependencies'] + p['optional-dependencies']['paddle']))" > /tmp/requirements.txt \
    && if [ "$USE_CONSTRAINTS" = "1" ]; then pip install -c constraints.txt -r /tmp/requirements.txt; else pip install -r /tmp/requirements.txt; fi \
    && rm /tmp/requirements.txt
COPY app ./app
COPY entrypoint.sh .
RUN chmod +x entrypoint.sh
USER ocr

# Produção: baixa os modelos do PaddleOCR na construção da imagem (PREFETCH_MODELS=1), para que o container não precise
# de internet em execução e o primeiro OCR não demore. Em desenvolvimento (padrão 0) os modelos são baixados no primeiro uso.
ARG PREFETCH_MODELS=0
RUN if [ "$PREFETCH_MODELS" = "1" ]; then \
        python -m app.warmup; \
    fi

EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/health || exit 1

ENTRYPOINT ["./entrypoint.sh"]
