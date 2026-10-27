#!/usr/bin/env bash
# Testes do script privilegiado de deploy (bulai-deploy) e do comando forçado do CI (bulai-ci-entry), com `docker`,
# `curl` e `sudo` substituídos por stubs. Não toca em Docker/VPS reais. Uso: scripts/deploy/tests/run.sh
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY="$HERE/../bulai-deploy"
ENTRY="$HERE/../bulai-ci-entry"
PASS=0; FAIL=0
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT

SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
SHA2=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
CSUM=$(printf 'c%.0s' {1..64})
D1="sha256:$(printf '1%.0s' {1..64})"; D2="sha256:$(printf '2%.0s' {1..64})"; D3="sha256:$(printf '3%.0s' {1..64})"; D4="sha256:$(printf '4%.0s' {1..64})"
E1="sha256:$(printf 'a%.0s' {1..64})"; E2="sha256:$(printf 'b%.0s' {1..64})"

ok()   { PASS=$((PASS + 1)); echo "  ok   - $1"; }
bad()  { FAIL=$((FAIL + 1)); echo "  FAIL - $1 ${2:+($2)}"; }
expect_eq() { if [[ "$2" == "$3" ]]; then ok "$1"; else bad "$1" "esperado '$3', obtido '$2'"; fi; }
expect_contains() { if grep -q -- "$3" <<<"$2"; then ok "$1"; else bad "$1" "não contém '$3'"; fi; }
expect_not_contains() { if grep -q -- "$3" <<<"$2"; then bad "$1" "contém '$3'"; else ok "$1"; fi; }

# --- ambiente de teste ------------------------------------------------------------------------------------------------
new_env() {
    SHA_IN_USE=$SHA
    ROOT="$WORK/$1"; rm -rf "$ROOT"; mkdir -p "$ROOT"/{releases,trusted,shared/env,shared/models,shared/postgres,shared/storage,shared/run,shared/web-cache,stub,bin}
    chmod 700 "$ROOT"/shared/postgres "$ROOT"/shared/web-cache; chmod 750 "$ROOT"/shared/storage; chmod 2750 "$ROOT"/shared/run; chmod 755 "$ROOT"/shared/models
    echo "services: {}" >"$ROOT/trusted/compose.prod.yaml"
    COMPOSE_SUM=$(sha256sum "$ROOT/trusted/compose.prod.yaml" | cut -d' ' -f1)
    printf 'LOCAL_AI_MODEL_FILENAME=model.gguf\n' >"$ROOT/trusted/model.env"
    echo x >"$ROOT/shared/models/model.gguf"
    printf 'APP_KEY=base64:x\nDB_DATABASE=b\nDB_USERNAME=u\nDB_PASSWORD=p\nDB_QUEUE_RETRY_AFTER=900\n' >"$ROOT/shared/env/app.env"
    printf 'POSTGRES_USER=a\nPOSTGRES_PASSWORD=p\nBULAI_DB_PASSWORD=p\n' >"$ROOT/shared/env/postgres.env"
    printf 'OCR_API_TOKEN=t\n' >"$ROOT/shared/env/ocr.env"
    chmod 600 "$ROOT"/shared/env/*.env
    : >"$ROOT/stub/calls.log"
    cat >"$ROOT/bin/docker" <<'STUB'
#!/usr/bin/env bash
echo "docker $*" >>"$STUB_DIR/calls.log"
args="$*"
case "$args" in
    "compose version"*) echo "Docker Compose version v2.0.0"; exit 0 ;;
    "network inspect"*) [[ ${STUB_NO_NETWORK:-0} == 1 ]] && exit 1; exit 0 ;;
    "pull"*) exit 0 ;;
    "image inspect"*)
        case "$args" in
            *org.opencontainers.image.source*) echo "${STUB_SOURCE:-https://github.com/BiGSerial/bulai}" ;;
            *org.opencontainers.image.revision*) echo "${STUB_REVISION:-$STUB_EXPECTED_SHA}" ;;
        esac; exit 0 ;;
    "image ls"*) [[ $# -gt 6 ]] && { echo "requires at most 1 argument" >&2; exit 1; }; exit 0 ;;
    "image rm"*) exit 0 ;;
    "inspect"*) echo healthy; exit 0 ;;
esac
if [[ $1 == compose ]]; then
    case "$args" in
        *" config -q"*) exit 0 ;;
        *" ps --status running --services"*) [[ -s "$STUB_DIR/up.count" || ${STUB_PG_RUNNING:-0} == 1 ]] && echo postgres; exit 0 ;;
        *" ps -q "*) echo "cid-${args##* }"; exit 0 ;;
        *" exec -T postgres"*) echo "PGDMP-fake-dump"; exit 0 ;;
        *" exec -T app"*) exit 0 ;;
        *" run --rm"*) [[ ${STUB_FAIL:-} == migrate ]] && exit 1; echo "migrated"; exit 0 ;;
        *" up -d --wait"*postgres*) exit 0 ;;
        *" up -d --remove-orphans"*)
            n=$(cat "$STUB_DIR/up.count" 2>/dev/null || echo 0); n=$((n + 1)); echo "$n" >"$STUB_DIR/up.count"
            echo "UP#$n APP_IMAGE=$APP_IMAGE" >>"$STUB_DIR/calls.log"
            [[ $n -le ${STUB_FAIL_UP:-0} ]] && exit 1; exit 0 ;;
    esac
fi
exit 0
STUB
    cat >"$ROOT/bin/curl" <<'STUB'
#!/usr/bin/env bash
# Falha enquanto o contador de `up` for igual a STUB_CURL_FAIL_AT_UP (simula uma release que sobe mas não responde).
if [[ -n ${STUB_CURL_FAIL_AT_UP:-} && "$(cat "$STUB_DIR/up.count" 2>/dev/null || echo 0)" == "$STUB_CURL_FAIL_AT_UP" ]]; then echo 503; else echo 200; fi
STUB
    chmod +x "$ROOT/bin/docker" "$ROOT/bin/curl"
}

run_deploy() { # <args...> ; usa $ROOT; imprime saída e define RC
    OUT=$(env PATH="$ROOT/bin:$PATH" STUB_DIR="$ROOT/stub" STUB_EXPECTED_SHA="${SHA_IN_USE:-$SHA}" BULAI_TEST_ROOT="$ROOT" BULAI_TEST_PROJECT=t BULAI_TEST_SKIP_PULL=0 BULAI_TEST_SMOKE_TRIES=2 BULAI_TEST_SMOKE_SLEEP=0 "$@" 2>&1); RC=$?
}
deploy() { run_deploy "$DEPLOY" "$@"; }
args_for() { echo "$1 $CSUM_USE $2 $3 $4 $5"; } # (não usado)

echo "== validação de argumentos"
new_env t1
CSUM_USE=$COMPOSE_SUM
deploy activate "zzz" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4";                expect_eq "sha inválido -> 2" "$RC" 2
deploy activate "$SHA" "abc" "$D1" "$D2" "$D3" "$D4";                         expect_eq "hash do compose inválido -> 2" "$RC" 2
deploy activate "$SHA" "$COMPOSE_SUM" "sha256:xyz" "$D2" "$D3" "$D4";         expect_eq "digest inválido -> 2" "$RC" 2
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3";                      expect_eq "argumentos faltando -> 2" "$RC" 2
deploy activate "$SHA" "$COMPOSE_SUM" "$D1; id" "$D2" "$D3" "$D4";            expect_eq "injeção em digest -> 2" "$RC" 2
deploy comando-desconhecido;                                                  expect_eq "verbo desconhecido -> 2" "$RC" 2

echo "== pré-requisitos"
new_env t2
deploy activate "$SHA" "$(printf 'd%.0s' {1..64})" "$D1" "$D2" "$D3" "$D4";   expect_eq "compose divergente -> 20" "$RC" 20
expect_not_contains "nenhum container alterado quando o compose diverge" "$(cat "$ROOT/stub/calls.log")" "up -d"
new_env t3; chmod 644 "$ROOT/shared/env/app.env"
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4";                expect_eq "app.env com permissão insegura -> 10" "$RC" 10
new_env t4; rm "$ROOT/shared/models/model.gguf"
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4";                expect_eq "modelo ausente -> 10" "$RC" 10
new_env t5; rmdir "$ROOT/shared/run"
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4";                expect_eq "diretório do socket ausente -> 10" "$RC" 10
new_env t6
STUB_REVISION=$SHA2 deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4"; expect_eq "imagem com revision diferente -> 10" "$RC" 10
new_env t7
STUB_SOURCE=https://github.com/evil/repo deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4"; expect_eq "imagem de outro repositório -> 10" "$RC" 10
new_env t5b; chmod 755 "$ROOT/shared/postgres"
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4";                expect_eq "PostgreSQL com modo aberto (755) -> 10" "$RC" 10
expect_contains "mensagem aponta o diretório do banco" "$OUT" "shared/postgres com modo 755"
new_env t5c; chmod 755 "$ROOT/shared/run"
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4";                expect_eq "diretório do socket sem setgid/2750 -> 10" "$RC" 10
new_env t5d; rmdir "$ROOT/shared/web-cache"
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4";                expect_eq "web-cache ausente -> 10" "$RC" 10
new_env t8
deploy check "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4";                   expect_eq "check OK -> 0" "$RC" 0
expect_not_contains "check não sobe containers" "$(cat "$ROOT/stub/calls.log")" "up -d"

echo "== activate (sucesso)"
new_env a1
STUB_PG_RUNNING=1 deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4"; expect_eq "primeira release -> 0" "$RC" 0
STATE=$(cat "$ROOT/state/state.env")
expect_contains "estado guarda a release atual" "$STATE" "CURRENT_SHA=$SHA"
expect_contains "estado guarda o digest da imagem da aplicação" "$STATE" "CURRENT_APP=$D1"
expect_eq "manifest da release criado em diretório somente-root (state/)" "$(test -f "$ROOT/state/releases/$SHA/manifest.txt" && echo sim)" "sim"
expect_eq "nada é escrito no diretório releases do usuário deploy" "$(ls -A "$ROOT/releases" 2>/dev/null | wc -l)" "0"
CALLS=$(cat "$ROOT/stub/calls.log")
mig=$(grep -n "run --rm" "$ROOT/stub/calls.log" | head -1 | cut -d: -f1); up=$(grep -n "up -d --remove-orphans" "$ROOT/stub/calls.log" | head -1 | cut -d: -f1)
if [[ -n $mig && -n $up && $mig -lt $up ]]; then ok "migrations rodam ANTES de trocar os containers"; else bad "ordem migrate/up" "mig=$mig up=$up"; fi
expect_contains "migrations usam --force (nunca migrate:fresh)" "$CALLS" "migrate --force"
expect_not_contains "nunca usa migrate:fresh" "$CALLS" "migrate:fresh"
expect_contains "imagens por digest no registro" "$CALLS" "ghcr.io/bigserial/bulai-app@$D1"
expect_eq "backup do banco criado antes de migrar" "$(ls "$ROOT"/backups/db/*.dump 2>/dev/null | wc -l)" "1"
expect_eq "backup com permissão 600" "$(stat -c '%a' "$ROOT"/backups/db/*.dump)" "600"

echo "== segunda release e rollback"
SHA_IN_USE=$SHA2
deploy activate "$SHA2" "$COMPOSE_SUM" "$E1" "$E2" "$D3" "$D4";               expect_eq "segunda release -> 0" "$RC" 0
STATE=$(cat "$ROOT/state/state.env")
expect_contains "atual é a nova" "$STATE" "CURRENT_SHA=$SHA2"
expect_contains "anterior é a primeira" "$STATE" "PREVIOUS_SHA=$SHA"
SHA_IN_USE=$SHA
: >"$ROOT/stub/calls.log"
deploy rollback;                                                              expect_eq "rollback -> 0" "$RC" 0
STATE=$(cat "$ROOT/state/state.env")
expect_contains "rollback troca atual/anterior" "$STATE" "CURRENT_SHA=$SHA"
expect_contains "rollback preserva a que saiu como anterior" "$STATE" "PREVIOUS_SHA=$SHA2"
expect_contains "rollback sobe as imagens anteriores" "$(cat "$ROOT/stub/calls.log")" "APP_IMAGE=ghcr.io/bigserial/bulai-app@$D1"
expect_not_contains "rollback não roda migrations" "$(cat "$ROOT/stub/calls.log")" "run --rm"
SHA_IN_USE=$SHA

echo "== falhas durante a release"
new_env f1
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4" >/dev/null
SHA_IN_USE=$SHA2
STUB_FAIL=migrate deploy activate "$SHA2" "$COMPOSE_SUM" "$E1" "$E2" "$D3" "$D4"; RC_M=$RC
expect_eq "falha na migration -> rollback automático (30)" "$RC_M" 30
expect_contains "estado continua na release anterior" "$(cat "$ROOT/state/state.env")" "CURRENT_SHA=$SHA"
new_env f2
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4" >/dev/null
echo 0 >"$ROOT/stub/up.count"
SHA_IN_USE=$SHA2
STUB_FAIL_UP=1 deploy activate "$SHA2" "$COMPOSE_SUM" "$E1" "$E2" "$D3" "$D4"
[[ ${DEBUG:-0} == 1 ]] && echo "$OUT" | tail -8
expect_eq "falha ao aplicar -> restaura a anterior (30)" "$RC" 30
expect_contains "restauração sobe a imagem anterior" "$(cat "$ROOT/stub/calls.log")" "APP_IMAGE=ghcr.io/bigserial/bulai-app@$D1"
expect_contains "estado não promove a release que falhou" "$(cat "$ROOT/state/state.env")" "CURRENT_SHA=$SHA"
new_env f3
echo 0 >"$ROOT/stub/up.count"
STUB_FAIL_UP=1 deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4"
expect_eq "primeira release que falha -> 31 (sem anterior)" "$RC" 31
expect_eq "nada é promovido" "$(test -f "$ROOT/state/state.env" && echo existe || echo nao)" "nao"
new_env f4
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4" >/dev/null
echo 0 >"$ROOT/stub/up.count"; SHA_IN_USE=$SHA2
STUB_FAIL_UP=9 deploy activate "$SHA2" "$COMPOSE_SUM" "$E1" "$E2" "$D3" "$D4"
expect_eq "restauração também falha -> 32" "$RC" 32
new_env f5
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4" >/dev/null
SHA_IN_USE=$SHA2
STUB_CURL_FAIL_AT_UP=2 deploy activate "$SHA2" "$COMPOSE_SUM" "$E1" "$E2" "$D3" "$D4"
expect_eq "smoke test falho -> rollback automático (30)" "$RC" 30
SHA_IN_USE=$SHA

new_env f6
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4" >/dev/null
: >"$ROOT/stub/up.count"; : >"$ROOT/stub/calls.log"; SHA_IN_USE=$SHA2
deploy activate "$SHA2" "$COMPOSE_SUM" "$E1" "$E2" "$D3" "$D4"
expect_eq "PostgreSQL parado com release ativa -> 31 (sem backup, sem deploy)" "$RC" 31
expect_not_contains "nenhuma migration quando o backup é impossível" "$(cat "$ROOT/stub/calls.log")" "run --rm --no-deps -T app php artisan migrate"
SHA_IN_USE=$SHA

echo "== concorrência"
new_env c1
mkdir -p "$ROOT/state"; ( exec 9>"$ROOT/state/deploy.lock"; flock -n 9; sleep 2 ) &
sleep 0.5
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4";                expect_eq "segunda implantação simultânea -> 40" "$RC" 40
wait

echo "== logs (serviços permitidos)"
new_env l1
deploy activate "$SHA" "$COMPOSE_SUM" "$D1" "$D2" "$D3" "$D4" >/dev/null
deploy logs "worker; id" 10;                                                  expect_eq "serviço inválido em logs -> 2" "$RC" 2
deploy logs worker "10; id";                                                  expect_eq "linhas inválidas em logs -> 2" "$RC" 2
deploy logs worker 20;                                                        expect_eq "logs válido -> 0" "$RC" 0
expect_contains "logs chama compose logs --tail" "$(cat "$ROOT/stub/calls.log")" "logs --no-color --tail 20 worker"

echo "== rollback sem release anterior"
new_env r1
deploy rollback;                                                              expect_eq "rollback sem anterior -> 31" "$RC" 31

echo "== consistência de UIDs/GIDs (compose × Dockerfiles × instalador × deploy)"
REPO="$HERE/../../.."
uid_of() { grep -oE "$2" "$1" | head -1 | grep -oE '[0-9]{5}' | head -1; }
expect_eq "compose: postgres roda como 10020" "$(grep -A1 'user: "10020:10020"' "$REPO/compose.prod.yaml" | head -1 | grep -c 10020)" "1"
expect_eq "compose: redis roda como 10040" "$(grep -c 'user: "10040:10040"' "$REPO/compose.prod.yaml")" "1"
expect_eq "compose: nginx roda como 10030" "$(grep -c 'user: "10030:10030"' "$REPO/compose.prod.yaml")" "1"
expect_eq "passwd do postgres usa 10020" "$(grep -c '^postgres:x:10020:10020:' "$REPO/docker/production/postgres/passwd")" "1"
expect_eq "group do postgres usa 10020" "$(grep -c '^postgres:x:10020:' "$REPO/docker/production/postgres/group")" "1"
expect_eq "Dockerfile da aplicação usa uid/gid 10010" "$(grep -c -- '--gid 10010\|--uid 10010' "$REPO/docker/production/php/Dockerfile")" "2"
expect_eq "instalador define os mesmos IDs" "$(grep -c 'APP_UID=10010 OCR_UID=10001 AI_UID=10002 PG_UID=10020 WEB_UID=10030 REDIS_UID=10040' "$REPO/scripts/deploy/install-on-vps.sh")" "1"
expect_eq "bulai-deploy confere os mesmos donos" "$(grep -cE 'check_dir (postgres 10020 10020 700|storage 10010 10010 750|web-cache 10030 10030 700|run 10010 10030 2750)' "$REPO/scripts/deploy/bulai-deploy")" "4"
for bad in 999 101; do
    expect_eq "nenhum container usa o ID $bad do host (caddy/messagebus/lxd)" "$(grep -cE "(user: \"$bad:|uid=${bad}[,\"]|gid=${bad}[,\"]|-o $bad |-g $bad )" "$REPO/compose.prod.yaml" "$REPO/scripts/deploy/install-on-vps.sh" | awk -F: '{s+=$2} END {print s+0}')" "0"
done

echo "== comando forçado do CI (bulai-ci-entry)"
# O comando forçado fixa o PATH (e usa sudo); no teste, troca-se só a chamada final por um eco.
sed 's#exec sudo -n#exec echo SUDO -n#' "$ENTRY" >"$WORK/entry-under-test"
entry() { OUT=$(env SSH_ORIGINAL_COMMAND="$1" bash "$WORK/entry-under-test" 2>&1); RC=$?; }
entry "status";                                         expect_eq "status permitido" "$RC" 0; expect_contains "status chama sudo bulai-deploy" "$OUT" "SUDO -n /usr/local/sbin/bulai-deploy status"
entry "rollback";                                       expect_eq "rollback permitido" "$RC" 0
entry "activate $SHA $CSUM $D1 $D2 $D3 $D4";            expect_eq "activate válido permitido" "$RC" 0; expect_contains "activate repassa os argumentos" "$OUT" "activate $SHA"
entry "check $SHA $CSUM $D1 $D2 $D3 $D4";               expect_eq "check válido permitido" "$RC" 0
entry "";                                               expect_eq "comando vazio recusado" "$RC" 2
entry "bash -i";                                        expect_eq "shell recusado" "$RC" 2
entry "status; id";                                     expect_eq "encadeamento recusado" "$RC" 2
entry "status \$(id)";                                  expect_eq "substituição de comando recusada" "$RC" 2
entry "activate $SHA $CSUM $D1 $D2 $D3";                expect_eq "argumentos faltando recusados" "$RC" 2
entry "activate $SHA $CSUM $D1 $D2 $D3 sha256:zz";      expect_eq "digest inválido recusado" "$RC" 2
entry "activate ${SHA}x $CSUM $D1 $D2 $D3 $D4";         expect_eq "sha inválido recusado" "$RC" 2
entry "rollback extra";                                 expect_eq "rollback com argumento recusado" "$RC" 2
entry "scp -t /tmp";                                    expect_eq "scp recusado" "$RC" 2
entry "status
id";                                                    expect_eq "quebra de linha recusada" "$RC" 2

echo
echo "Resultado: $PASS ok, $FAIL falhas"
[[ $FAIL -eq 0 ]]
