#!/usr/bin/env bash
# Instala/atualiza o CONJUNTO CONFIÁVEL de implantação do BULA-IA na VPS. Execute como root, a partir de um checkout
# REVISADO do repositório (um administrador, não o CI):
#
#   sudo scripts/deploy/install-on-vps.sh                 instala/atualiza e mostra os próximos passos
#   sudo scripts/deploy/install-on-vps.sh --check         só compara o repositório com o que está instalado (drift)
#   sudo scripts/deploy/install-on-vps.sh --ci-public-key-file /caminho/chave.pub   também autoriza a chave do CI
#
# O que instala (tudo somente-root, fora de qualquer diretório do usuário deploy/CI):
#   /usr/local/sbin/bulai-deploy          script privilegiado de deploy
#   /usr/local/bin/bulai-ci-entry         comando forçado da chave SSH do CI
#   /etc/sudoers.d/bulai-ci               única permissão sudo do usuário de CI
#   /opt/bulai/trusted/                   compose.prod.yaml, init do PostgreSQL, model.env, instalador do modelo
#   /srv/bulai/shared/{env,postgres,storage,models,run,web-cache}   segredos, dados, arquivos privados, modelo, socket do PHP-FPM e buffer do Nginx
# E prepara diretórios/permissões em /srv/bulai. NÃO altera o Caddy, a rede Docker nem os usuários existentes
# (willhome, deploy); apenas cria o usuário de sistema `bulai-ci` se ele não existir.
set -Eeuo pipefail
umask 022

[[ ${EUID} -eq 0 ]] || { echo "Execute como root (sudo)." >&2; exit 2; }
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"
[[ -f compose.prod.yaml && -f scripts/deploy/bulai-deploy ]] || { echo "Execute a partir de um checkout do BULA-IA." >&2; exit 2; }

# O instalador roda como root: só aceita executar a partir de uma árvore que pertença ao root e não seja gravável por
# grupo/outros, INCLUINDO todos os diretórios ancestrais. Um checkout do usuário `deploy` (ou do CI) permitiria que esse
# usuário injetasse código que o root executaria.
SOURCE_FILES=(
    scripts/deploy/install-on-vps.sh scripts/deploy/bulai-deploy scripts/deploy/bulai-ci-entry scripts/deploy/sudoers-bulai-ci
    scripts/install-local-model.sh compose.prod.yaml docker/production/env/model.env docker/production/postgres/init/10-bulai-app-role.sh
    docker/production/postgres/passwd docker/production/postgres/group
)
path_untrusted() { # <caminho> -> 0 se NÃO for confiável
    local path owner mode
    path=$(realpath -e "$1") || return 0
    while :; do
        owner=$(stat -c '%u' "$path"); mode=$(stat -c '%a' "$path")
        if [[ $owner != 0 ]] || (( (8#$mode & 8#022) != 0 )); then
            echo "Origem não confiável: $path (dono=$owner modo=$mode). Use uma cópia root:root (veja docs/deployment/kinghost.md, seção 4)." >&2
            return 0
        fi
        [[ $path == / ]] && break
        path=$(dirname "$path")
    done
    return 1
}
for rel in "${SOURCE_FILES[@]}"; do
    if path_untrusted "$REPO_ROOT/$rel"; then exit 2; fi
done

CHECK_ONLY=0
CI_KEY_FILE=
while [[ $# -gt 0 ]]; do
    case "$1" in
        --check) CHECK_ONLY=1 ;;
        --ci-public-key-file) CI_KEY_FILE=${2:?informe o arquivo da chave pública}; shift ;;
        -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
        *) echo "Argumento desconhecido: $1" >&2; exit 2 ;;
    esac
    shift
done

TRUSTED=/opt/bulai/trusted
SHARED=/srv/bulai/shared
CI_USER=bulai-ci

# origem -> destino -> modo
FILES=(
    "scripts/deploy/bulai-deploy|/usr/local/sbin/bulai-deploy|0750"
    "scripts/deploy/bulai-ci-entry|/usr/local/bin/bulai-ci-entry|0755"
    "scripts/deploy/sudoers-bulai-ci|/etc/sudoers.d/bulai-ci|0440"
    "compose.prod.yaml|$TRUSTED/compose.prod.yaml|0644"
    "docker/production/env/model.env|$TRUSTED/model.env|0644"
    "scripts/install-local-model.sh|$TRUSTED/install-local-model.sh|0755"
    "docker/production/postgres/init/10-bulai-app-role.sh|$TRUSTED/docker/production/postgres/init/10-bulai-app-role.sh|0755"
    "docker/production/postgres/passwd|$TRUSTED/docker/production/postgres/passwd|0644"
    "docker/production/postgres/group|$TRUSTED/docker/production/postgres/group|0644"
)

drift=0
for entry in "${FILES[@]}"; do
    IFS='|' read -r src dst mode <<<"$entry"
    repo_sum=$(sha256sum "$src" | cut -d' ' -f1)
    if [[ -f $dst ]]; then
        inst_sum=$(sha256sum "$dst" | cut -d' ' -f1)
        if [[ $repo_sum == "$inst_sum" ]]; then status="igual"; else status="DIFERENTE"; drift=1; fi
    else
        status="AUSENTE"; drift=1
    fi
    printf '%-10s %s\n' "$status" "$dst"
done
echo "commit do repositório: $(git rev-parse HEAD 2>/dev/null || echo desconhecido)"
echo "sha256 do compose.prod.yaml: $(sha256sum compose.prod.yaml | cut -d' ' -f1)"
# ---- UIDs/GIDs dos containers --------------------------------------------------------------------------------------
# Os bind mounts de /srv/bulai/shared pertencem a estes IDs. Se uma conta do host usar o mesmo uid/gid (ex.: 999=caddy,
# 101=messagebus/lxd na KingHost), essa conta teria acesso ao PostgreSQL, aos arquivos privados ou ao socket do PHP-FPM.
# Por isso os containers usam IDs altos e o instalador RECUSA continuar se algum já existir no host (não altera contas).
APP_UID=10010 OCR_UID=10001 AI_UID=10002 PG_UID=10020 WEB_UID=10030 REDIS_UID=10040
collision=0
for id in "$OCR_UID" "$AI_UID" "$APP_UID" "$PG_UID" "$WEB_UID" "$REDIS_UID"; do
    if getent passwd "$id" >/dev/null; then echo "ERRO: uid $id pertence a '$(getent passwd "$id" | cut -d: -f1)' no host." >&2; collision=1; fi
    if getent group "$id" >/dev/null; then echo "ERRO: gid $id pertence a '$(getent group "$id" | cut -d: -f1)' no host." >&2; collision=1; fi
done
if [[ $collision -ne 0 ]]; then
    echo "Colisão de uid/gid com contas do host: escolha outros IDs em compose.prod.yaml, Dockerfiles e neste instalador (não altere as contas da VPS)." >&2
    exit 1
fi
# User namespaces/rootless mudam o mapeamento dos donos dos bind mounts (uid do container -> subuid do host): não suportado.
if docker info --format '{{json .SecurityOptions}}' 2>/dev/null | grep -Eq 'name=(userns|rootless)'; then
    echo "O daemon Docker usa userns-remap/rootless: os donos dos bind mounts precisariam ser remapeados. Não suportado por este instalador." >&2
    exit 1
fi
echo "uids/gids dos containers livres no host; Docker sem userns/rootless"
if [[ $CHECK_ONLY -eq 1 ]]; then exit "$drift"; fi

# ---- usuário de CI ---------------------------------------------------------------------------------------------------
if ! id "$CI_USER" >/dev/null 2>&1; then
    useradd --system --create-home --home-dir "/home/$CI_USER" --shell /bin/bash --comment "BULA-IA CI/CD (comando forçado)" "$CI_USER"
    usermod -p '*' "$CI_USER"   # sem senha válida; o acesso é só pela chave com comando forçado
    echo "criado usuário de sistema $CI_USER (senha bloqueada)"
fi
install -d -m 0700 -o "$CI_USER" -g "$CI_USER" "/home/$CI_USER/.ssh"
touch "/home/$CI_USER/.ssh/authorized_keys"
chown "$CI_USER:$CI_USER" "/home/$CI_USER/.ssh/authorized_keys"
chmod 0600 "/home/$CI_USER/.ssh/authorized_keys"

# ---- diretórios ------------------------------------------------------------------------------------------------------
install -d -m 0755 -o root -g root /opt/bulai "$TRUSTED" "$TRUSTED/docker" "$TRUSTED/docker/production" "$TRUSTED/docker/production/postgres" "$TRUSTED/docker/production/postgres/init"
install -d -m 0755 -o root -g root /srv/bulai "$SHARED"
install -d -m 0700 -o root -g root "$SHARED/env" /srv/bulai/backups /var/lib/bulai-deploy
# Dados do PostgreSQL: dono 10020 (container). Se já houver um banco inicializado com outro dono (ex.: o uid 999 padrão da imagem),
# a posse é migrada para 10020 para que o container consiga lê-lo e o uid 999 (caddy) deixe de ter acesso.
install -d -m 0700 -o "$PG_UID" -g "$PG_UID" "$SHARED/postgres"
if [[ -f $SHARED/postgres/PG_VERSION && $(stat -c '%u' "$SHARED/postgres/PG_VERSION") != "$PG_UID" ]]; then
    echo "migrando a posse do banco existente em $SHARED/postgres para $PG_UID:$PG_UID"
    chown -R "$PG_UID:$PG_UID" "$SHARED/postgres"
fi
install -d -m 0750 -o "$APP_UID" -g "$APP_UID" "$SHARED/storage"       # arquivos privados (usuário `bulai` da imagem da aplicação)
install -d -m 0700 -o "$WEB_UID" -g "$WEB_UID" "$SHARED/web-cache"     # buffer em disco do Nginx
install -d -m 0755 -o root -g root "$SHARED/models"          # somente leitura nos containers
chown -R root:root "$SHARED/models"                          # conteúdo antigo não pode ficar com outro dono
# Socket do PHP-FPM compartilhado só com o Nginx: dono = usuário da aplicação (10010), grupo = nginx (10030), setgid:
# nenhuma conta do host está nesse grupo, então só os dois containers alcançam o socket.
install -d -m 2750 -o "$APP_UID" -g "$WEB_UID" "$SHARED/run"
# Releases: o usuário deploy continua dono (informativo). Só cria se ainda não existir.
[[ -d /srv/bulai/releases ]] || install -d -m 0755 /srv/bulai/releases

# ---- arquivos confiáveis ---------------------------------------------------------------------------------------------
for entry in "${FILES[@]}"; do
    IFS='|' read -r src dst mode <<<"$entry"
    if [[ $dst == /etc/sudoers.d/* ]]; then
        tmp=$(mktemp); cp "$src" "$tmp"
        visudo -cf "$tmp" >/dev/null || { rm -f "$tmp"; echo "sudoers inválido; abortado." >&2; exit 1; }
        install -m "$mode" -o root -g root "$tmp" "$dst"; rm -f "$tmp"
    else
        install -m "$mode" -o root -g root "$src" "$dst"
    fi
    echo "instalado $dst"
done

# Rotação do log do deploy.
cat >/etc/logrotate.d/bulai-deploy <<'EOF'
/var/log/bulai-deploy.log {
    monthly
    rotate 12
    compress
    missingok
    notifempty
    create 0640 root adm
}
EOF

# ---- chave do CI (opcional) ------------------------------------------------------------------------------------------
if [[ -n $CI_KEY_FILE ]]; then
    key=$(grep -E '^(ssh-ed25519|ecdsa-sha2-nistp256|ssh-rsa) ' "$CI_KEY_FILE" | head -1 | tr -d '\r')
    [[ -n $key ]] || { echo "chave pública inválida em $CI_KEY_FILE" >&2; exit 1; }
    line="restrict,command=\"/usr/local/bin/bulai-ci-entry\" $key"
    if grep -qF "$key" "/home/$CI_USER/.ssh/authorized_keys"; then
        echo "chave do CI já autorizada"
    else
        echo "$line" >>"/home/$CI_USER/.ssh/authorized_keys"
        echo "chave do CI autorizada com comando forçado"
    fi
fi

cat <<EOF

Instalação concluída. Próximos passos (veja docs/deployment/kinghost.md):
  1. sudo bulai-deploy init-secrets                       # cria /srv/bulai/shared/env/*.env (preencha BULAI_ADMIN_*)
  2. sudo bulai-deploy ensure-model                       # baixa e valida (SHA-256) o modelo da IA local
  3. sudo docker login ghcr.io                            # token SOMENTE de leitura de pacotes (read:packages)
  4. autorizar a chave do CI:  sudo $0 --ci-public-key-file <chave.pub>
  5. acionar o deploy pelo GitHub Actions (workflow_dispatch). O Caddy só é trocado depois da validação.
EOF
