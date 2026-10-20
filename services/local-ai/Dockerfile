# Classificador local da ingestão (llamafile). CPU-only; não exige GPU.
FROM python:3.11-slim

ARG LLAMAFILE_VERSION=0.9.3
ARG LLAMAFILE_SHA256=c62b303d2fa6ca6829f10cf9b3c9bfae38a28182eacea92502a399b90547b24b

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates \
    && mkdir -p /opt/llamafile \
    && curl -fL --retry 3 -o /opt/llamafile/llamafile "https://github.com/Mozilla-Ocho/llamafile/releases/download/${LLAMAFILE_VERSION}/llamafile-${LLAMAFILE_VERSION}" \
    && echo "${LLAMAFILE_SHA256}  /opt/llamafile/llamafile" | sha256sum -c - \
    && chmod +x /opt/llamafile/llamafile \
    && apt-get purge -y curl && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --uid 10002 localai

WORKDIR /srv
COPY app ./app
COPY config ./config
COPY entrypoint.sh .
RUN chmod +x entrypoint.sh
USER localai

EXPOSE 8080
ENTRYPOINT ["./entrypoint.sh"]
