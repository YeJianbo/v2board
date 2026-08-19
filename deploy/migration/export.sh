#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SITE_ROOT="/www/wwwroot/v2.151376.xyz"
OUTPUT_DIR="/root/v2board-migration"
OUTPUT_NAME=""
CUTOVER=0

usage() {
  cat <<'EOF'
Usage: bash export.sh [options]

Options:
  --site-root PATH    Laravel site root (default: /www/wwwroot/v2.151376.xyz)
  --output-dir PATH   Output directory (default: /root/v2board-migration)
  --output-name NAME  Archive base name without extension
  --cutover           Put Laravel in maintenance mode and stop Horizon before export
  -h, --help          Show this help

Set MIGRATION_PASSWORD to create an AES-256 encrypted archive. Without it, the
archive is stored as a root-only .tar.gz file. Transfer it only over SSH/SCP.
EOF
}

while (($#)); do
  case "$1" in
    --site-root)
      SITE_ROOT="${2:?missing --site-root value}"
      shift 2
      ;;
    --output-dir)
      OUTPUT_DIR="${2:?missing --output-dir value}"
      shift 2
      ;;
    --output-name)
      OUTPUT_NAME="${2:?missing --output-name value}"
      shift 2
      ;;
    --cutover)
      CUTOVER=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ "${EUID}" -ne 0 && "${CUTOVER}" -eq 1 ]]; then
  echo "--cutover must run as root." >&2
  exit 1
fi
if [[ "${EUID}" -ne 0 ]]; then
  echo "Running an application-level migration export. Root-only TLS files may be omitted."
fi

if [[ ! -f "${SITE_ROOT}/artisan" || ! -f "${SITE_ROOT}/.env" ]]; then
  echo "Invalid Laravel site root: ${SITE_ROOT}" >&2
  exit 1
fi

for command_name in tar gzip sha256sum openssl; do
  if ! command -v "${command_name}" >/dev/null 2>&1; then
    echo "Missing required command: ${command_name}" >&2
    exit 1
  fi
done

PHP_BIN=""
for candidate in /www/server/php/81/bin/php /www/server/php/82/bin/php /usr/bin/php "$(command -v php 2>/dev/null || true)"; do
  if [[ -n "${candidate}" && -x "${candidate}" ]]; then
    PHP_BIN="${candidate}"
    break
  fi
done
if [[ -z "${PHP_BIN}" ]]; then
  echo "PHP CLI was not found." >&2
  exit 1
fi

MYSQL_DUMP_BIN="$(command -v mysqldump 2>/dev/null || command -v mariadb-dump 2>/dev/null || true)"
if [[ -z "${MYSQL_DUMP_BIN}" ]]; then
  echo "mysqldump or mariadb-dump was not found." >&2
  exit 1
fi

read_env_value() {
  local key="$1"
  local line value
  line="$(grep -m1 -E "^${key}=" "${SITE_ROOT}/.env" || true)"
  value="${line#*=}"
  if [[ "${value}" =~ ^\".*\"$ || "${value}" =~ ^\'.*\'$ ]]; then
    value="${value:1:${#value}-2}"
  fi
  printf '%s' "${value}"
}

DB_HOST="$(read_env_value DB_HOST)"
DB_PORT="$(read_env_value DB_PORT)"
DB_DATABASE="$(read_env_value DB_DATABASE)"
DB_USERNAME="$(read_env_value DB_USERNAME)"
DB_PASSWORD="$(read_env_value DB_PASSWORD)"
APP_URL="$(read_env_value APP_URL)"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

if [[ -z "${DB_DATABASE}" || -z "${DB_USERNAME}" ]]; then
  echo "DB_DATABASE or DB_USERNAME is missing from .env." >&2
  exit 1
fi

STAMP="$(date +%Y%m%d-%H%M%S)"
OUTPUT_NAME="${OUTPUT_NAME:-v2board-migration-${STAMP}}"
mkdir -p "${OUTPUT_DIR}"

WORK_DIR="$(mktemp -d /tmp/v2board-export.XXXXXX)"
BUNDLE_ROOT="${WORK_DIR}/bundle"
MYSQL_CNF="${WORK_DIR}/mysql-client.cnf"

cleanup() {
  rm -rf -- "${WORK_DIR}"
}
trap cleanup EXIT

mkdir -p "${BUNDLE_ROOT}/payload" "${BUNDLE_ROOT}/system" "${BUNDLE_ROOT}/certs"

cat >"${MYSQL_CNF}" <<EOF
[client]
host=${DB_HOST}
port=${DB_PORT}
user=${DB_USERNAME}
password=${DB_PASSWORD}
default-character-set=utf8mb4
EOF
chmod 600 "${MYSQL_CNF}"

if ((CUTOVER)); then
  echo "Entering cutover mode: enabling maintenance mode and stopping Horizon."
  (cd "${SITE_ROOT}" && "${PHP_BIN}" artisan down --retry=60) || true
  if command -v supervisorctl >/dev/null 2>&1; then
    supervisorctl stop 'v2board-horizon:*' 2>/dev/null || supervisorctl stop 'v:*' 2>/dev/null || true
  elif [[ -x /www/server/panel/pyenv/bin/supervisorctl ]]; then
    /www/server/panel/pyenv/bin/supervisorctl stop 'v2board-horizon:*' 2>/dev/null \
      || /www/server/panel/pyenv/bin/supervisorctl stop 'v:*' 2>/dev/null \
      || true
  fi
fi

echo "Dumping database ${DB_DATABASE}..."
SQL_DUMP="${WORK_DIR}/database.sql"
"${MYSQL_DUMP_BIN}" \
  --defaults-extra-file="${MYSQL_CNF}" \
  --no-tablespaces \
  --single-transaction \
  --quick \
  --routines \
  --events \
  --triggers \
  --hex-blob \
  --default-character-set=utf8mb4 \
  "${DB_DATABASE}" >"${SQL_DUMP}"
if [[ ! -s "${SQL_DUMP}" ]]; then
  echo "Database dump is empty." >&2
  exit 1
fi
gzip -1c "${SQL_DUMP}" >"${BUNDLE_ROOT}/payload/database.sql.gz"
gzip -t "${BUNDLE_ROOT}/payload/database.sql.gz"

echo "Archiving site files..."
tar \
  --exclude='./.git' \
  --exclude='./node_modules' \
  --exclude='./storage/logs/*' \
  --exclude='./storage/framework/cache/data/*' \
  --exclude='./storage/framework/sessions/*' \
  --exclude='./storage/framework/views/*' \
  --exclude='./storage/app/backups/*' \
  --exclude='./storage/app/backup/*' \
  --exclude='./bootstrap/cache/*.php' \
  --exclude='./.codex-*' \
  -C "${SITE_ROOT}" \
  -czf "${BUNDLE_ROOT}/payload/site.tar.gz" .

NGINX_CONFIG=""
for candidate in \
  "/www/server/panel/vhost/nginx/$(basename "${SITE_ROOT}").conf" \
  "/etc/nginx/sites-enabled/$(basename "${SITE_ROOT}")" \
  "/etc/nginx/conf.d/$(basename "${SITE_ROOT}").conf"; do
  if [[ -f "${candidate}" ]]; then
    NGINX_CONFIG="${candidate}"
    cp -a "${candidate}" "${BUNDLE_ROOT}/system/nginx-source.conf"
    break
  fi
done

SERVER_NAMES=""
if [[ -n "${NGINX_CONFIG}" ]]; then
  SERVER_NAMES="$(awk '$1 == "server_name" {for (i=2; i<=NF; i++) {gsub(";", "", $i); printf "%s%s", sep, $i; sep=" "} exit}' "${NGINX_CONFIG}")"

  CERT_FILE="$(awk '$1 == "ssl_certificate" {gsub(";", "", $2); print $2; exit}' "${NGINX_CONFIG}")"
  CERT_KEY_FILE="$(awk '$1 == "ssl_certificate_key" {gsub(";", "", $2); print $2; exit}' "${NGINX_CONFIG}")"
  if [[ -n "${CERT_FILE}" && -r "${CERT_FILE}" ]]; then
    cp -L "${CERT_FILE}" "${BUNDLE_ROOT}/certs/fullchain.pem"
  fi
  if [[ -n "${CERT_KEY_FILE}" && -r "${CERT_KEY_FILE}" ]]; then
    cp -L "${CERT_KEY_FILE}" "${BUNDLE_ROOT}/certs/privkey.pem"
  fi
fi

if [[ -f /www/server/panel/plugin/supervisor/profile/v.ini ]]; then
  cp -a /www/server/panel/plugin/supervisor/profile/v.ini "${BUNDLE_ROOT}/system/supervisor-source.ini"
elif [[ -f /etc/supervisor/conf.d/v2board.conf ]]; then
  cp -a /etc/supervisor/conf.d/v2board.conf "${BUNDLE_ROOT}/system/supervisor-source.ini"
fi

crontab -l >"${BUNDLE_ROOT}/system/root.crontab" 2>/dev/null || true
if [[ -s "${BUNDLE_ROOT}/system/root.crontab" && -d /www/server/cron ]]; then
  mkdir -p "${BUNDLE_ROOT}/system/bt-cron"
  while IFS= read -r cron_script; do
    case "${cron_script}" in
      *.log|*.pl) continue ;;
    esac
    if [[ -f "${cron_script}" ]]; then
      cp -a "${cron_script}" "${BUNDLE_ROOT}/system/bt-cron/$(basename "${cron_script}")"
    fi
  done < <(grep -oE '/www/server/cron/[A-Za-z0-9._-]+' "${BUNDLE_ROOT}/system/root.crontab" | sort -u || true)
fi
if [[ -r /etc/cron.d/v2board-scheduler ]]; then
  cp -a /etc/cron.d/v2board-scheduler "${BUNDLE_ROOT}/system/v2board-scheduler.cron"
fi
"${PHP_BIN}" -m | sort >"${BUNDLE_ROOT}/system/php-modules.txt"
systemctl list-unit-files >"${BUNDLE_ROOT}/system/systemd-units.txt" 2>/dev/null || true

DOMAIN="${APP_URL#*://}"
DOMAIN="${DOMAIN%%/*}"
DOMAIN="${DOMAIN%%:*}"
SERVER_NAMES="${SERVER_NAMES:-${DOMAIN}}"

{
  printf 'BUNDLE_VERSION=%q\n' "1"
  printf 'CREATED_AT=%q\n' "$(date --iso-8601=seconds)"
  printf 'SOURCE_SITE_ROOT=%q\n' "${SITE_ROOT}"
  printf 'APP_URL=%q\n' "${APP_URL}"
  printf 'PRIMARY_DOMAIN=%q\n' "${DOMAIN}"
  printf 'SERVER_NAMES=%q\n' "${SERVER_NAMES}"
  printf 'SOURCE_PHP_VERSION=%q\n' "$("${PHP_BIN}" -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
  printf 'SOURCE_ARCH=%q\n' "$(uname -m)"
  printf 'SOURCE_OS=%q\n' "$(. /etc/os-release; echo "${ID}-${VERSION_ID}")"
  printf 'DB_DATABASE=%q\n' "${DB_DATABASE}"
  printf 'CUTOVER_EXPORT=%q\n' "${CUTOVER}"
} >"${BUNDLE_ROOT}/metadata.env"

cp -a "${SCRIPT_DIR}/restore.sh" "${BUNDLE_ROOT}/restore.sh"
chmod 700 "${BUNDLE_ROOT}/restore.sh"

(cd "${BUNDLE_ROOT}" && sha256sum payload/site.tar.gz payload/database.sql.gz >SHA256SUMS)

PLAIN_ARCHIVE="${OUTPUT_DIR}/${OUTPUT_NAME}.tar.gz"
tar -C "${WORK_DIR}" -czf "${PLAIN_ARCHIVE}" bundle
chmod 600 "${PLAIN_ARCHIVE}"

FINAL_ARCHIVE="${PLAIN_ARCHIVE}"
if [[ -n "${MIGRATION_PASSWORD:-}" ]]; then
  FINAL_ARCHIVE="${PLAIN_ARCHIVE}.enc"
  openssl enc -aes-256-cbc -salt -pbkdf2 -iter 250000 \
    -in "${PLAIN_ARCHIVE}" \
    -out "${FINAL_ARCHIVE}" \
    -pass env:MIGRATION_PASSWORD
  chmod 600 "${FINAL_ARCHIVE}"
  rm -f -- "${PLAIN_ARCHIVE}"
fi

cp -a "${SCRIPT_DIR}/restore.sh" "${OUTPUT_DIR}/restore.sh"
chmod 700 "${OUTPUT_DIR}/restore.sh"
(cd "${OUTPUT_DIR}" && sha256sum "$(basename "${FINAL_ARCHIVE}")" >"$(basename "${FINAL_ARCHIVE}").sha256")
chmod 600 "${FINAL_ARCHIVE}.sha256"

echo
echo "Migration export completed."
echo "Archive: ${FINAL_ARCHIVE}"
echo "Restore: ${OUTPUT_DIR}/restore.sh"
echo "SHA256:  ${FINAL_ARCHIVE}.sha256"
if [[ -z "${MIGRATION_PASSWORD:-}" ]]; then
  echo "WARNING: The archive contains .env, database data, and TLS keys. Keep mode 600 and transfer only over SSH."
fi
if ((CUTOVER)); then
  echo "The source site remains in maintenance mode and Horizon remains stopped for cutover."
fi
