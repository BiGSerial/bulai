# syntax=docker/dockerfile:1.7
# Nginx interno do BULA-IA. Serve os assets estáticos (copiados da MESMA imagem `app`, então o manifest do Vite
# sempre coincide com o código) e repassa o restante ao PHP-FPM. Roda sem root (porta 8080 no container).
#
#   docker build -f docker/production/nginx/Dockerfile \
#     --build-arg APP_IMAGE=ghcr.io/bigserial/bulai-app@sha256:<digest> --build-arg GIT_SHA=<sha> .
ARG APP_IMAGE=bulai-app:localtest
ARG NGINX_IMAGE=nginxinc/nginx-unprivileged:1.28-alpine@sha256:7377697a821c131a924a7105fafbe7414db4e9fcc77a6f08f776f33f141ec3f8

FROM ${APP_IMAGE} AS app

FROM ${NGINX_IMAGE}
ARG GIT_SHA=unknown
ARG GIT_REPOSITORY=https://github.com/BiGSerial/bulai
LABEL org.opencontainers.image.title="bulai-web" \
      org.opencontainers.image.source="${GIT_REPOSITORY}" \
      org.opencontainers.image.revision="${GIT_SHA}"

USER root
# Corpos de requisição grandes (PDFs) e respostas do FastCGI vão para disco (volume em /var/cache/nginx), não para a RAM do container.
RUN rm -f /etc/nginx/conf.d/default.conf \
    && sed -i 's#/tmp/client_temp#/var/cache/nginx/client_temp#; s#/tmp/fastcgi_temp#/var/cache/nginx/fastcgi_temp#' /etc/nginx/nginx.conf \
    && grep -q '/var/cache/nginx/client_temp' /etc/nginx/nginx.conf
COPY docker/production/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/production/nginx/fastcgi_params_bulai /etc/nginx/fastcgi_params_bulai
COPY --from=app /var/www/html/public /var/www/html/public
RUN chmod -R a=rX /var/www/html/public
USER nginx

EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/nginx-health || exit 1
