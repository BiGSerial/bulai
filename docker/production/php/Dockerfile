# syntax=docker/dockerfile:1.7
# Imagem de produção do BULA-IA (Laravel + PHP-FPM). A mesma imagem executa `app` (php-fpm), `worker` (queue:work) e
# tarefas pontuais (migrate). Não contém .env, credenciais nem dados: tudo vem do ambiente em tempo de execução.
#
# Build (o contexto é a raiz do repositório):
#   docker build -f docker/production/php/Dockerfile --build-arg GIT_SHA=$(git rev-parse HEAD) -t bulai-app:$(git rev-parse HEAD) .

ARG PHP_IMAGE=php:8.4.27-fpm-bookworm@sha256:df5a5113e1179ddf4560cd15727eb3812f6457829756d2a96c6c11bb4c250089
ARG COMPOSER_IMAGE=composer:2.8@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c
ARG NODE_IMAGE=node:22-bookworm-slim@sha256:c3de60bf2f9dd0ac6370e6117950ff62d6e339527e7472301c9c78a017978392

FROM ${COMPOSER_IMAGE} AS composer-bin

# Base PHP com as extensões exigidas pelo projeto (composer check-platform-reqs) e pelo runtime.
FROM ${PHP_IMAGE} AS php-base
# `apt-get upgrade` aplica as correções de segurança do Debian publicadas depois do build da imagem-base (fixada por digest).
RUN apt-get update \
    && apt-get upgrade -y --no-install-recommends \
    && apt-get install -y --no-install-recommends libpq5 libzip4 libpng16-16 libjpeg62-turbo libfreetype6 libicu72 libfcgi-bin \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl opcache gd zip intl \
    && apt-get purge -y libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev \
    && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# 1) Dependências PHP de produção (sem dev, com autoload otimizado).
FROM php-base AS vendor
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . .
RUN mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/app storage/logs bootstrap/cache \
    && composer install --no-dev --prefer-dist --no-progress --optimize-autoloader --classmap-authoritative \
    && composer check-platform-reqs --no-dev

# 2) Assets (Vite). Tailwind lê as views do vendor/ e do app, por isso parte do estágio vendor.
FROM ${NODE_IMAGE} AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /app /app
RUN npm run build && rm -rf node_modules

# 3) Imagem final.
FROM php-base AS app
ARG GIT_SHA=unknown
ARG GIT_REPOSITORY=https://github.com/BiGSerial/bulai
LABEL org.opencontainers.image.title="bulai-app" \
      org.opencontainers.image.source="${GIT_REPOSITORY}" \
      org.opencontainers.image.revision="${GIT_SHA}"

COPY docker/production/php/php.ini /usr/local/etc/php/conf.d/zz-bulai.ini
COPY docker/production/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-bulai.conf
COPY docker/production/php/entrypoint.sh /usr/local/bin/bulai-entrypoint
RUN rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf \
    && chmod 0755 /usr/local/bin/bulai-entrypoint \
    && groupadd --system --gid 10010 bulai \
    && useradd --system --uid 10010 --gid bulai --home-dir /nonexistent --shell /usr/sbin/nologin bulai

WORKDIR /var/www/html
COPY --from=assets --chown=root:root /app /var/www/html
# Código imutável (root:root, somente leitura para o usuário da aplicação). Só estes caminhos são graváveis
# em tempo de execução, e todos são volumes ou tmpfs no Compose.
RUN chown root:root /var/www/html && chmod 0755 /var/www/html \
    && mkdir -p storage/app storage/logs storage/framework/views storage/framework/cache/data storage/framework/sessions storage/framework/testing bootstrap/cache \
    && chown -R bulai:bulai storage bootstrap/cache \
    && chmod -R u+rwX,go-rwx storage bootstrap/cache

ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
USER bulai
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=3 \
    CMD ["bulai-entrypoint", "healthcheck"]
ENTRYPOINT ["bulai-entrypoint"]
CMD ["php-fpm", "--nodaemonize"]
