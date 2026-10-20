#!/bin/sh
# Entrypoint da imagem de produção.
#   php-fpm …       -> prepara diretórios graváveis, aquece caches com o ambiente real e inicia o processo
#   healthcheck     -> consulta o ping do PHP-FPM
#   qualquer outro  -> executa o comando (ex.: php artisan migrate --force)
set -eu

if [ "${1:-}" = "healthcheck" ]; then
    out="$(SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect /run/php/bulai.sock 2>/dev/null || true)"
    case "$out" in *pong*) exit 0 ;; *) exit 1 ;; esac
fi

cd /var/www/html

# storage/framework é tmpfs (vazio a cada início); recria a estrutura que o Laravel espera.
mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/app/php-uploads storage/logs

if [ -z "${APP_KEY:-}" ]; then
    echo "bulai-entrypoint: APP_KEY ausente; recusando iniciar." >&2
    exit 64
fi

# Caches gerados com o ambiente real (nunca na imagem). Falhas aqui não devem impedir o diagnóstico, mas são registradas.
case "${1:-}" in
    php-fpm|php)
        php artisan config:cache --no-interaction >/dev/null
        php artisan route:cache --no-interaction >/dev/null
        php artisan view:cache --no-interaction >/dev/null
        php artisan event:cache --no-interaction >/dev/null
        ;;
esac

exec "$@"
