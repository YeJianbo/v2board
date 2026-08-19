#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

SITE_ROOT="${V2BOARD_SITE_ROOT:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)}"
TYPE="${V2BOARD_BACKUP_TYPE:-database}"
OUTPUT_DIR="${V2BOARD_BACKUP_PATH:-${SITE_ROOT}/storage/app/backups}"
REMOTE="${V2BOARD_BACKUP_REMOTE:-}"
KEEP_DAYS="${V2BOARD_BACKUP_KEEP_DAYS:-14}"
STATUS_FILE="${V2BOARD_BACKUP_STATUS_FILE:-${SITE_ROOT}/storage/app/backup/status.json}"
LOCK_FILE="${V2BOARD_BACKUP_LOCK_FILE:-${SITE_ROOT}/storage/app/backup/backup.lock}"

usage() {
  cat <<'EOF'
Usage: backup.sh [--type database|migration] [--output-dir PATH]
                 [--remote RCLONE_REMOTE] [--keep-days DAYS]

Examples:
  backup.sh --type database
  backup.sh --type migration --remote gdrive:buncloud-backups
  backup.sh --type migration --remote ftp:buncloud-backups

Google Drive, FTP, FTPS and SFTP use a preconfigured rclone remote. Run
`rclone config` once, then enter REMOTE as `remote-name:path`.
EOF
}

while (($#)); do
  case "$1" in
    --site-root) SITE_ROOT="${2:?missing value}"; shift 2 ;;
    --type) TYPE="${2:?missing value}"; shift 2 ;;
    --output-dir) OUTPUT_DIR="${2:?missing value}"; shift 2 ;;
    --remote) REMOTE="${2:?missing value}"; shift 2 ;;
    --keep-days) KEEP_DAYS="${2:?missing value}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ "${TYPE}" != "database" && "${TYPE}" != "migration" ]]; then
  echo "Invalid backup type: ${TYPE}" >&2
  exit 2
fi
if ! [[ "${KEEP_DAYS}" =~ ^[0-9]+$ ]] || ((KEEP_DAYS < 1)); then
  echo "--keep-days must be a positive integer." >&2
  exit 2
fi
if [[ ! -f "${SITE_ROOT}/.env" ]]; then
  echo "Invalid site root: ${SITE_ROOT}" >&2
  exit 1
fi

mkdir -p "${OUTPUT_DIR}" "$(dirname "${STATUS_FILE}")" "$(dirname "${LOCK_FILE}")"
exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
  echo "Another backup is already running." >&2
  exit 75
fi

STARTED_AT="$(date --iso-8601=seconds)"
BACKUP_FILE=""
REMOTE_FILE=""

write_status() {
  local status="$1" message="$2" completed_at="${3:-}"
  jq -n \
    --arg status "${status}" \
    --arg type "${TYPE}" \
    --arg started_at "${STARTED_AT}" \
    --arg completed_at "${completed_at}" \
    --arg file "${BACKUP_FILE}" \
    --arg remote "${REMOTE_FILE}" \
    --arg message "${message}" \
    '{status:$status,type:$type,started_at:$started_at,completed_at:$completed_at,file:$file,remote:$remote,message:$message}' \
    >"${STATUS_FILE}.tmp"
  mv "${STATUS_FILE}.tmp" "${STATUS_FILE}"
  chmod 600 "${STATUS_FILE}"
}

on_error() {
  local exit_code=$?
  write_status failed "Backup failed with exit code ${exit_code}" "$(date --iso-8601=seconds)"
  exit "${exit_code}"
}
trap on_error ERR
write_status running "Backup is running"

read_env_value() {
  local key="$1" line value
  line="$(grep -m1 -E "^${key}=" "${SITE_ROOT}/.env" || true)"
  value="${line#*=}"
  if [[ "${value}" =~ ^\".*\"$ || "${value}" =~ ^\'.*\'$ ]]; then
    value="${value:1:${#value}-2}"
  fi
  printf '%s' "${value}"
}

STAMP="$(date +%Y%m%d-%H%M%S)"
if [[ "${TYPE}" == "database" ]]; then
  DB_HOST="$(read_env_value DB_HOST)"
  DB_PORT="$(read_env_value DB_PORT)"
  DB_NAME="$(read_env_value DB_DATABASE)"
  DB_USER="$(read_env_value DB_USERNAME)"
  DB_PASSWORD="$(read_env_value DB_PASSWORD)"
  DB_HOST="${DB_HOST:-127.0.0.1}"
  DB_PORT="${DB_PORT:-3306}"
  MYSQL_CNF="$(mktemp)"
  trap 'rm -f "${MYSQL_CNF}"' EXIT
  cat >"${MYSQL_CNF}" <<EOF
[client]
host=${DB_HOST}
port=${DB_PORT}
user=${DB_USER}
password=${DB_PASSWORD}
default-character-set=utf8mb4
EOF
  chmod 600 "${MYSQL_CNF}"
  BACKUP_FILE="${OUTPUT_DIR}/v2board-database-${STAMP}.sql.gz"
  mysqldump --defaults-extra-file="${MYSQL_CNF}" --single-transaction --quick --routines --events --triggers --hex-blob --no-tablespaces "${DB_NAME}" | gzip -1 >"${BACKUP_FILE}"
  gzip -t "${BACKUP_FILE}"
  rm -f "${MYSQL_CNF}"
  trap - EXIT
else
  EXPORT_DIR="$(mktemp -d /tmp/v2board-backup-export.XXXXXX)"
  EXPORT_ARGS=(--site-root "${SITE_ROOT}" --output-dir "${EXPORT_DIR}" --output-name "v2board-migration-${STAMP}")
  MIGRATION_PASSWORD="${V2BOARD_BACKUP_PASSWORD:-}" bash "${SITE_ROOT}/deploy/migration/export.sh" "${EXPORT_ARGS[@]}"
  BACKUP_FILE="$(find "${EXPORT_DIR}" -maxdepth 1 -type f \( -name '*.tar.gz' -o -name '*.tar.gz.enc' \) | head -n 1)"
  if [[ -z "${BACKUP_FILE}" ]]; then
    echo "Migration exporter did not create an archive." >&2
    exit 1
  fi
  mv "${BACKUP_FILE}" "${OUTPUT_DIR}/$(basename "${BACKUP_FILE}")"
  BACKUP_FILE="${OUTPUT_DIR}/$(basename "${BACKUP_FILE}")"
  [[ -f "${EXPORT_DIR}/$(basename "${BACKUP_FILE}").sha256" ]] && mv "${EXPORT_DIR}/$(basename "${BACKUP_FILE}").sha256" "${OUTPUT_DIR}/"
  rm -rf "${EXPORT_DIR}"
fi

if [[ -n "${REMOTE}" ]]; then
  if ! command -v rclone >/dev/null 2>&1; then
    echo "rclone is required for remote backup." >&2
    exit 1
  fi
  REMOTE_FILE="${REMOTE%/}/$(basename "${BACKUP_FILE}")"
  rclone copyto "${BACKUP_FILE}" "${REMOTE_FILE}" --checkers 2 --transfers 1 --retries 3 --low-level-retries 10
  rclone delete "${REMOTE%/}" --min-age "${KEEP_DAYS}d" --include 'v2board-database-*.sql.gz' --include 'v2board-migration-*.tar.gz' --include 'v2board-migration-*.tar.gz.enc' || true
fi

find "${OUTPUT_DIR}" -maxdepth 1 -type f \( -name 'v2board-database-*.sql.gz' -o -name 'v2board-migration-*.tar.gz' -o -name 'v2board-migration-*.tar.gz.enc' -o -name '*.sha256' \) -mtime "+${KEEP_DAYS}" -delete
write_status success "Backup completed" "$(date --iso-8601=seconds)"
echo "Backup completed: ${BACKUP_FILE}"
