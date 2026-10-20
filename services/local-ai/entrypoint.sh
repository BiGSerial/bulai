#!/bin/sh
# Entrypoint do local-ai: sempre sobe o supervisor (que reporta MODEL_NOT_INSTALLED se não houver modelo).
set -eu

if [ -f /srv/config/defaults.env ]; then
    # Só aplica o padrão quando a variável não veio do ambiente.
    while IFS='=' read -r key value; do
        case "$key" in ''|'#'*) continue ;; esac
        eval "[ -n \"\${$key:-}\" ] || export $key=\"$value\""
    done < /srv/config/defaults.env
fi

exec python3 -m app.supervisor
