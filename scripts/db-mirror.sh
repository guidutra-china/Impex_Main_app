#!/usr/bin/env bash
#
# db-mirror.sh — espelha o banco de PRODUÇÃO (Forge) para o banco de DEV local.
#
# Como funciona:
#   1. Conecta via SSH no servidor Forge.
#   2. Lê as credenciais do banco direto do .env do site (nada fica salvo aqui).
#   3. Faz mysqldump consistente (sem travar a produção), comprimido.
#   4. Baixa o dump para ~/db-mirror/ (mantém um backup datado).
#   5. Recria o banco local e importa.
#   6. Roda `php artisan migrate` + limpa caches.
#
# Uso:
#   FORGE_SSH=forge@SEU_IP ./scripts/db-mirror.sh
#   (ou edite SSH_HOST abaixo uma vez)
#
set -euo pipefail

# ─────────────────────────── CONFIG ───────────────────────────
# Host SSH do Forge. Pegue o IP em Forge → Server. Usuário é sempre "forge".
SSH_HOST="${FORGE_SSH:-forge@CHANGE_ME}"

# Caminho do app no servidor (Forge → Site). Normalmente /home/forge/<dominio>.
REMOTE_PATH="${FORGE_APP_PATH:-/home/forge/app.impex.ltd}"

# Raiz do projeto local (este script vive em <projeto>/scripts/).
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Onde guardar os dumps baixados.
DUMP_DIR="$HOME/db-mirror"
# ───────────────────────────────────────────────────────────────

if [[ "$SSH_HOST" == *CHANGE_ME* ]]; then
  echo "✗ Configure o host SSH primeiro:" >&2
  echo "    FORGE_SSH=forge@SEU_IP $0" >&2
  echo "  ou edite SSH_HOST no topo deste arquivo." >&2
  exit 1
fi

# Lê config do banco LOCAL a partir do .env do projeto.
get_env() { sed -n "s/^$1=//p" "$PROJECT_ROOT/.env" | head -1 | tr -d '"'; }
LOCAL_DB="$(get_env DB_DATABASE)"
LOCAL_USER="$(get_env DB_USERNAME)"
LOCAL_PASS="$(get_env DB_PASSWORD)"
LOCAL_HOST="$(get_env DB_HOST)"; LOCAL_HOST="${LOCAL_HOST:-127.0.0.1}"
LOCAL_PORT="$(get_env DB_PORT)"; LOCAL_PORT="${LOCAL_PORT:-3306}"

# Localiza o cliente `mysql` local. Use LOCAL_MYSQL=/caminho/mysql para forçar.
find_local_mysql() {
  if [[ -n "${LOCAL_MYSQL:-}" ]]; then echo "$LOCAL_MYSQL"; return 0; fi
  if command -v mysql >/dev/null 2>&1; then command -v mysql; return 0; fi
  local c
  for c in \
    /opt/homebrew/bin/mysql \
    /opt/homebrew/opt/mysql-client/bin/mysql \
    /opt/homebrew/opt/mysql/bin/mysql \
    /usr/local/bin/mysql \
    /usr/local/opt/mysql-client/bin/mysql \
    /usr/local/mysql/bin/mysql \
    /Users/Shared/DBngin/mysql/*/bin/mysql \
    "$HOME/Library/Application Support/Herd"/bin/mysql \
    "$HOME/Library/Application Support/Herd"/*/*/bin/mysql ; do
    [[ -x "$c" ]] && { echo "$c"; return 0; }
  done
  return 1
}

MYSQL_BIN="$(find_local_mysql)" || {
  echo "✗ Cliente 'mysql' não encontrado no Mac." >&2
  echo "  Instale com:  brew install mysql-client" >&2
  echo "  ou aponte o caminho:  LOCAL_MYSQL=/caminho/para/mysql $0" >&2
  echo "  (o dump já está salvo em $HOME/db-mirror/, não precisa baixar de novo)" >&2
  exit 1
}

mysql_local() {
  MYSQL_PWD="$LOCAL_PASS" "$MYSQL_BIN" -u"$LOCAL_USER" -h"$LOCAL_HOST" -P"$LOCAL_PORT" "$@"
}

echo "  Produção : $SSH_HOST:$REMOTE_PATH"
echo "  Local    : $LOCAL_DB  ($LOCAL_USER@$LOCAL_HOST:$LOCAL_PORT)"
echo
read -rp "⚠  Isto APAGA e substitui o banco local '$LOCAL_DB'. Continuar? [s/N] " ok
[[ "$ok" =~ ^[sSyY]$ ]] || { echo "Cancelado."; exit 0; }

mkdir -p "$DUMP_DIR"
STAMP="$(date +%Y%m%d_%H%M%S)"
DUMP_FILE="$DUMP_DIR/prod_${STAMP}.sql.gz"

echo "→ [1/4] Garantindo o mysql-client no servidor…"
ssh "$SSH_HOST" 'command -v mysqldump >/dev/null 2>&1 || { echo "  instalando mysql-client (sudo)…"; sudo apt-get update -qq && sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq mysql-client; }'

echo "→ [2/4] Dump do banco gerenciado (DigitalOcean), via servidor…"
# As credenciais de produção são lidas e usadas DENTRO do servidor Forge
# (que já está liberado no Trusted Sources do banco). Nada trafega pra cá em claro.
ssh "$SSH_HOST" 'bash -s' "$REMOTE_PATH" <<'REMOTE_SCRIPT' > "$DUMP_FILE"
set -euo pipefail
cd "$1" || { echo "Caminho do app não encontrado: $1" >&2; exit 1; }
read_env() { sed -n "s/^$1=//p" .env | head -1 | tr -d '"'; }
DB="$(read_env DB_DATABASE)"; U="$(read_env DB_USERNAME)"; P="$(read_env DB_PASSWORD)"
H="$(read_env DB_HOST)"; PORT="$(read_env DB_PORT)"
MYSQL_PWD="$P" mysqldump \
  -h"$H" -P"${PORT:-3306}" --ssl-mode=REQUIRED \
  --single-transaction --quick --no-tablespaces \
  --column-statistics=0 --set-gtid-purged=OFF \
  --default-character-set=utf8mb4 \
  -u"$U" "$DB" | gzip
REMOTE_SCRIPT
echo "  Salvo em: $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))"

echo "→ [3/4] Recriando e importando banco local '$LOCAL_DB'…"
mysql_local -e "DROP DATABASE IF EXISTS \`$LOCAL_DB\`;
  CREATE DATABASE \`$LOCAL_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip < "$DUMP_FILE" | mysql_local "$LOCAL_DB"

echo "→ [4/4] Migrations + limpando caches…"
cd "$PROJECT_ROOT"
php artisan migrate --force
php artisan optimize:clear

echo "✓ Pronto. Banco local '$LOCAL_DB' espelhado da produção."
