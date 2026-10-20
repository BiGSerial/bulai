#!/bin/bash
# Executado pelo entrypoint do PostgreSQL apenas na PRIMEIRA inicialização de um volume vazio.
# Cria o papel da aplicação (sem superusuário), entrega a ele o banco e habilita a extensão pgvector (que exige
# superusuário). A senha do papel vem de BULAI_DB_PASSWORD (arquivo de ambiente privado da VPS); nunca fica no Git.
set -euo pipefail

: "${BULAI_DB_USER:?BULAI_DB_USER não definido}"
: "${BULAI_DB_PASSWORD:?BULAI_DB_PASSWORD não definido}"
if [ "${#BULAI_DB_PASSWORD}" -lt 24 ]; then
    echo "BULAI_DB_PASSWORD deve ter ao menos 24 caracteres." >&2
    exit 1
fi

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v app_user="$BULAI_DB_USER" -v app_password="$BULAI_DB_PASSWORD" -v app_db="$POSTGRES_DB" <<'SQL'
-- Papel da aplicação: pode logar e é dono do banco/esquema (necessário para as migrations), mas não é superusuário.
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE', :'app_user', :'app_password') \gexec
SELECT format('ALTER DATABASE %I OWNER TO %I', :'app_db', :'app_user') \gexec
SELECT format('ALTER SCHEMA public OWNER TO %I', :'app_user') \gexec
-- pgvector precisa de superusuário para ser criado; as migrations usam CREATE EXTENSION IF NOT EXISTS (sem efeito se já existir).
CREATE EXTENSION IF NOT EXISTS vector;
SQL
