#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

ARCHIVE=""
SITE_ROOT=""
DOMAIN=""
PHP_VERSION=""
FORCE=0
STANDBY=0
SKIP_PACKAGES=0
IS_BT_PANEL=0
MIGRATED_CRONTAB_PENDING=""

usage() {
  cat <<'EOF'
Usage: bash restore.sh --archive FILE [options]

Options:
  --archive FILE       Migration .tar.gz or .tar.gz.enc archive
  --site-root PATH     Override target site root
  --domain DOMAIN      Override primary domain
  --php-version X.Y    Require a specific installed PHP version
  --force              Backup an existing site and replace an existing database
  --standby            Install everything but keep Horizon and scheduler disabled
  --skip-packages      Do not install OS packages
  -h, --help           Show this help

For encrypted archives, set MIGRATION_PASSWORD or enter it when prompted.
EOF
}

while (($#)); do
  case "$1" in
    --archive)
      ARCHIVE="${2:?missing --archive value}"
      shift 2
      ;;
    --site-root)
      SITE_ROOT="${2:?missing --site-root value}"
      shift 2
      ;;
    --domain)
      DOMAIN="${2:?missing --domain value}"
      shift 2
      ;;
    --php-version)
      PHP_VERSION="${2:?missing --php-version value}"
      shift 2
      ;;
    --force)
      FORCE=1
      shift
      ;;
    --standby)
      STANDBY=1
      shift
      ;;
    --skip-packages)
      SKIP_PACKAGES=1
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

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run this script as root." >&2
  exit 1
fi
if [[ -z "${ARCHIVE}" || ! -f "${ARCHIVE}" ]]; then
  echo "A valid --archive file is required." >&2
  exit 1
fi
if ! command -v apt-get >/dev/null 2>&1 && ((SKIP_PACKAGES == 0)); then
  echo "Automatic package installation currently supports Debian and Ubuntu only." >&2
  exit 1
fi

if [[ -d /www/server/panel && -x /www/server/nginx/sbin/nginx ]]; then
  IS_BT_PANEL=1
fi

WORK_DIR="$(mktemp -d /tmp/v2board-restore.XXXXXX)"
DECRYPTED_ARCHIVE="${WORK_DIR}/migration.tar.gz"

cleanup() {
  rm -rf -- "${WORK_DIR}"
}
trap cleanup EXIT

if [[ "${ARCHIVE}" == *.enc ]]; then
  if [[ -z "${MIGRATION_PASSWORD:-}" ]]; then
    read -r -s -p "Migration password: " MIGRATION_PASSWORD
    echo
    export MIGRATION_PASSWORD
  fi
  openssl enc -d -aes-256-cbc -pbkdf2 -iter 250000 \
    -in "${ARCHIVE}" \
    -out "${DECRYPTED_ARCHIVE}" \
    -pass env:MIGRATION_PASSWORD
else
  cp -a "${ARCHIVE}" "${DECRYPTED_ARCHIVE}"
fi

tar -C "${WORK_DIR}" -xzf "${DECRYPTED_ARCHIVE}"
BUNDLE_ROOT="${WORK_DIR}/bundle"
if [[ ! -f "${BUNDLE_ROOT}/metadata.env" || ! -f "${BUNDLE_ROOT}/payload/site.tar.gz" || ! -f "${BUNDLE_ROOT}/payload/database.sql.gz" ]]; then
  echo "Invalid migration archive." >&2
  exit 1
fi

(cd "${BUNDLE_ROOT}" && sha256sum -c SHA256SUMS)

# shellcheck disable=SC1091
source "${BUNDLE_ROOT}/metadata.env"

SITE_ROOT="${SITE_ROOT:-${SOURCE_SITE_ROOT:-/www/wwwroot/v2board}}"
DOMAIN="${DOMAIN:-${PRIMARY_DOMAIN:-}}"
SERVER_NAMES="${SERVER_NAMES:-${DOMAIN}}"
PHP_VERSION="${PHP_VERSION:-${SOURCE_PHP_VERSION:-}}"

if [[ -z "${DOMAIN}" ]]; then
  echo "Primary domain is missing. Pass --domain." >&2
  exit 1
fi

if ((SKIP_PACKAGES == 0)); then
  export DEBIAN_FRONTEND=noninteractive
  PACKAGES=(ca-certificates cron curl gzip openssl rclone rsync tar unzip)
  if ! command -v mysql >/dev/null 2>&1; then
    PACKAGES+=(mariadb-client mariadb-server)
  fi
  if ! command -v redis-server >/dev/null 2>&1; then
    PACKAGES+=(redis-server)
  fi
  if ((IS_BT_PANEL)) && ! php -r 'exit(extension_loaded("redis") ? 0 : 1);' 2>/dev/null; then
    PACKAGES+=(autoconf build-essential pkg-config)
  fi
  if ((IS_BT_PANEL == 0)); then
    PACKAGES+=(nginx supervisor php-cli php-fpm php-bcmath php-curl php-gd php-intl php-mbstring php-mysql php-opcache php-redis php-soap php-sqlite3 php-xml php-zip composer)
  fi
  apt-get update
  apt-get install -y "${PACKAGES[@]}"
fi

if ((IS_BT_PANEL)); then
  PHP_BIN=""
  for candidate in \
    "/www/server/php/${PHP_VERSION//./}/bin/php" \
    /www/server/php/81/bin/php \
    /www/server/php/82/bin/php \
    /www/server/php/83/bin/php \
    /www/server/php/84/bin/php; do
    if [[ -x "${candidate}" ]]; then
      PHP_BIN="${candidate}"
      break
    fi
  done
  NGINX_BIN="/www/server/nginx/sbin/nginx"
  SUPERVISORCTL="/www/server/panel/pyenv/bin/supervisorctl"
else
  PHP_BIN="$(command -v php || true)"
  NGINX_BIN="$(command -v nginx || true)"
  SUPERVISORCTL="$(command -v supervisorctl || true)"
fi

for command_name in mysql gzip runuser sha256sum; do
  if ! command -v "${command_name}" >/dev/null 2>&1; then
    echo "Missing required command after installation: ${command_name}" >&2
    exit 1
  fi
done
for executable in "${PHP_BIN}" "${NGINX_BIN}" "${SUPERVISORCTL}"; do
  if [[ -z "${executable}" || ! -x "${executable}" ]]; then
    echo "Missing required executable: ${executable:-unknown}" >&2
    exit 1
  fi
done

INSTALLED_PHP_VERSION="$(${PHP_BIN} -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
if [[ -n "${PHP_VERSION}" && "${INSTALLED_PHP_VERSION}" != "${PHP_VERSION}" ]]; then
  echo "WARNING: source PHP is ${PHP_VERSION}, target PHP is ${INSTALLED_PHP_VERSION}."
fi

for service in mariadb mysql mysqld redis-server redis supervisor supervisord cron crond; do
  systemctl enable --now "${service}" 2>/dev/null || true
done

if ! redis-cli ping 2>/dev/null | grep -qx 'PONG'; then
  MULTIARCH="$(dpkg-architecture -qDEB_HOST_MULTIARCH 2>/dev/null || true)"
  if [[ -n "${MULTIARCH}" && -f "/usr/lib/${MULTIARCH}/libjemalloc.so.2" && -f /lib/systemd/system/redis-server.service ]]; then
    mkdir -p /etc/systemd/system/redis-server.service.d
    cat >/etc/systemd/system/redis-server.service.d/v2board-migration.conf <<EOF
[Service]
Environment="LD_LIBRARY_PATH=/usr/lib/${MULTIARCH}"
EOF
    systemctl daemon-reload
    systemctl reset-failed redis-server 2>/dev/null || true
    systemctl restart redis-server
  fi
fi
if ! redis-cli ping 2>/dev/null | grep -qx 'PONG'; then
  echo "Redis is installed but is not accepting connections on 127.0.0.1:6379." >&2
  exit 1
fi

PHP_VERSION_COMPACT="${INSTALLED_PHP_VERSION//./}"
if ((IS_BT_PANEL)) && systemctl list-unit-files --type=service --no-legend | grep -q "^php-fpm-${PHP_VERSION_COMPACT}\.service"; then
  PHP_FPM_SERVICE="php-fpm-${PHP_VERSION_COMPACT}.service"
else
  PHP_FPM_SERVICE="$(systemctl list-unit-files --type=service --no-legend | awk '/php[0-9.]+-fpm\.service|php-fpm-[0-9]+\.service/ {print $1}' | sort -V | tail -n 1)"
fi
if [[ -z "${PHP_FPM_SERVICE}" ]]; then
  echo "PHP-FPM service was not found." >&2
  exit 1
fi
systemctl enable --now "${PHP_FPM_SERVICE}"

PHP_CONFIG_ROOT="/etc/php/${INSTALLED_PHP_VERSION}"
if ((IS_BT_PANEL == 0)) && [[ -d "${PHP_CONFIG_ROOT}" ]]; then
  cat >"${PHP_CONFIG_ROOT}/mods-available/v2board.ini" <<'EOF'
memory_limit=512M
max_execution_time=300
upload_max_filesize=128M
post_max_size=128M
display_errors=Off
log_errors=On
error_reporting=E_ALL & ~E_DEPRECATED & ~E_STRICT
opcache.enable=1
opcache.enable_cli=1
EOF
  if command -v phpenmod >/dev/null 2>&1; then
    phpenmod -v "${INSTALLED_PHP_VERSION}" v2board
  fi
  systemctl restart "${PHP_FPM_SERVICE}"
fi

if ((IS_BT_PANEL)) && ! "${PHP_BIN}" -r 'exit(extension_loaded("redis") ? 0 : 1);' 2>/dev/null; then
  PECL_BIN="$(dirname "${PHP_BIN}")/pecl"
  if [[ ! -x "${PECL_BIN}" ]]; then
    echo "BT Panel PHP Redis extension is missing and pecl was not found: ${PECL_BIN}" >&2
    exit 1
  fi
  printf '\n\n\n\n\n\n' | "${PECL_BIN}" install redis
  for php_ini in "/www/server/php/${PHP_VERSION_COMPACT}/etc/php.ini" "/www/server/php/${PHP_VERSION_COMPACT}/etc/php-cli.ini"; do
    if [[ -f "${php_ini}" ]] && ! grep -Eq '^[[:space:]]*extension[[:space:]]*=[[:space:]]*redis(\.so)?[[:space:]]*$' "${php_ini}"; then
      printf '\nextension=redis.so\n' >>"${php_ini}"
    fi
  done
  systemctl restart "${PHP_FPM_SERVICE}"
  if ! "${PHP_BIN}" -r 'exit(extension_loaded("redis") ? 0 : 1);' 2>/dev/null; then
    echo "BT Panel PHP Redis extension installation did not become active." >&2
    exit 1
  fi
fi

MEMORY_MB="$(awk '/^MemTotal:/ {print int($2 / 1024)}' /proc/meminfo)"
if ((MEMORY_MB < 1500)); then
  FPM_MAX_CHILDREN=10
elif ((MEMORY_MB < 3000)); then
  FPM_MAX_CHILDREN=18
elif ((MEMORY_MB < 6000)); then
  FPM_MAX_CHILDREN=32
else
  FPM_MAX_CHILDREN=48
fi
if ((IS_BT_PANEL)); then
  FPM_CONFIG="/www/server/php/${PHP_VERSION_COMPACT}/etc/php-fpm.conf"
else
  FPM_CONFIG="/etc/php/${INSTALLED_PHP_VERSION}/fpm/pool.d/www.conf"
fi
if [[ -f "${FPM_CONFIG}" ]]; then
  sed -i \
    -e "s/^pm.max_children = .*/pm.max_children = ${FPM_MAX_CHILDREN}/" \
    -e 's/^pm.start_servers = .*/pm.start_servers = 4/' \
    -e 's/^pm.min_spare_servers = .*/pm.min_spare_servers = 3/' \
    -e 's/^pm.max_spare_servers = .*/pm.max_spare_servers = 8/' \
    -e 's/^;\?request_slowlog_timeout = .*/request_slowlog_timeout = 5s/' \
    "${FPM_CONFIG}"
  systemctl reload "${PHP_FPM_SERVICE}" || systemctl restart "${PHP_FPM_SERVICE}"
fi

if ((IS_BT_PANEL)); then
  PHP_SOCKET="/tmp/php-cgi-${PHP_VERSION_COMPACT}.sock"
else
  PHP_SOCKET="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' | sort -V | tail -n 1)"
fi
if [[ -z "${PHP_SOCKET}" ]]; then
  echo "PHP-FPM socket was not found." >&2
  exit 1
fi

read_env_file_value() {
  local file="$1"
  local key="$2"
  local line value
  [[ -f "${file}" ]] || return 0
  line="$(grep -m1 -E "^${key}=" "${file}" || true)"
  value="${line#*=}"
  if [[ "${value}" =~ ^\".*\"$ || "${value}" =~ ^\'.*\'$ ]]; then
    value="${value:1:${#value}-2}"
  fi
  printf '%s' "${value}"
}

STAMP="$(date +%Y%m%d-%H%M%S)"
ROLLBACK_DIR="/root/v2board-pre-migration-${STAMP}"
EXISTING_WEB_USER=""
EXISTING_WEB_GROUP=""
EXISTING_DB_HOST=""
EXISTING_DB_PORT=""
EXISTING_DB_NAME=""
EXISTING_DB_USER=""
EXISTING_DB_PASSWORD=""
if [[ -e "${SITE_ROOT}" ]]; then
  EXISTING_WEB_USER="$(stat -c '%U' "${SITE_ROOT}" 2>/dev/null || true)"
  EXISTING_WEB_GROUP="$(stat -c '%G' "${SITE_ROOT}" 2>/dev/null || true)"
  EXISTING_DB_HOST="$(read_env_file_value "${SITE_ROOT}/.env" DB_HOST)"
  EXISTING_DB_PORT="$(read_env_file_value "${SITE_ROOT}/.env" DB_PORT)"
  EXISTING_DB_NAME="$(read_env_file_value "${SITE_ROOT}/.env" DB_DATABASE)"
  EXISTING_DB_USER="$(read_env_file_value "${SITE_ROOT}/.env" DB_USERNAME)"
  EXISTING_DB_PASSWORD="$(read_env_file_value "${SITE_ROOT}/.env" DB_PASSWORD)"
fi
if [[ -e "${SITE_ROOT}" && -n "$(find "${SITE_ROOT}" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]]; then
  if ((FORCE == 0)); then
    echo "Target site root is not empty: ${SITE_ROOT}. Use --force to back it up and replace it." >&2
    exit 1
  fi
  mkdir -p "${ROLLBACK_DIR}"
  mv "${SITE_ROOT}" "${SITE_ROOT}.pre-migration-${STAMP}"
fi

mkdir -p "${SITE_ROOT}"
tar -C "${SITE_ROOT}" -xzf "${BUNDLE_ROOT}/payload/site.tar.gz"

read_env_value() {
  local key="$1"
  read_env_file_value "${SITE_ROOT}/.env" "${key}"
}

set_env_value() {
  local key="$1"
  local value="$2"
  local tmp
  tmp="$(mktemp)"
  awk -v key="${key}" -v value="${value}" '
    BEGIN { found=0 }
    index($0, key "=") == 1 { print key "=" value; found=1; next }
    { print }
    END { if (!found) print key "=" value }
  ' "${SITE_ROOT}/.env" >"${tmp}"
  cat "${tmp}" >"${SITE_ROOT}/.env"
  rm -f -- "${tmp}"
}

DB_NAME="$(read_env_value DB_DATABASE)"
DB_USER="$(read_env_value DB_USERNAME)"
DB_NAME="${DB_NAME:-${DB_DATABASE:-xboard}}"
if [[ ! "${DB_NAME}" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Unsafe database name: ${DB_NAME}" >&2
  exit 1
fi
if [[ -z "${DB_USER}" || "${DB_USER}" == "root" ]]; then
  DB_USER="v2board_app"
fi
if [[ ! "${DB_USER}" =~ ^[A-Za-z0-9_]+$ ]]; then
  DB_USER="v2board_app"
fi
DB_PASSWORD_NEW="$(openssl rand -hex 24)"
USE_EXISTING_DB_ACCESS=0

mysql_exec() {
  if ((USE_EXISTING_DB_ACCESS)); then
    MYSQL_PWD="${EXISTING_DB_PASSWORD}" mysql \
      -h "${EXISTING_DB_HOST:-127.0.0.1}" \
      -P "${EXISTING_DB_PORT:-3306}" \
      -u "${EXISTING_DB_USER}" "$@"
  else
    mysql "$@"
  fi
}

mysqldump_exec() {
  if ((USE_EXISTING_DB_ACCESS)); then
    MYSQL_PWD="${EXISTING_DB_PASSWORD}" mysqldump \
      -h "${EXISTING_DB_HOST:-127.0.0.1}" \
      -P "${EXISTING_DB_PORT:-3306}" \
      -u "${EXISTING_DB_USER}" "$@"
  else
    mysqldump "$@"
  fi
}

if ! mysql -Nse 'SELECT 1' >/dev/null 2>&1; then
  if [[ -z "${EXISTING_DB_USER}" || -z "${EXISTING_DB_NAME}" || "${EXISTING_DB_NAME}" != "${DB_NAME}" ]]; then
    echo "MySQL administrative access is unavailable and the existing site credentials cannot be reused." >&2
    exit 1
  fi
  USE_EXISTING_DB_ACCESS=1
  if ! mysql_exec "${DB_NAME}" -Nse 'SELECT 1' >/dev/null 2>&1; then
    echo "Existing site database credentials are not usable for migration." >&2
    exit 1
  fi
  DB_USER="${EXISTING_DB_USER}"
  DB_PASSWORD_NEW="${EXISTING_DB_PASSWORD}"
  echo "Reusing the existing site database account because MySQL root access is unavailable."
fi

TABLE_COUNT="$(mysql_exec -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'" 2>/dev/null || echo 0)"
if [[ "${TABLE_COUNT}" != "0" ]]; then
  if ((FORCE == 0)); then
    echo "Database ${DB_NAME} is not empty. Use --force to replace it." >&2
    exit 1
  fi
  mkdir -p "${ROLLBACK_DIR}"
  mysqldump_exec --single-transaction --quick --routines --triggers --events --no-tablespaces "${DB_NAME}" | gzip -9 >"${ROLLBACK_DIR}/${DB_NAME}.sql.gz"
  if ((USE_EXISTING_DB_ACCESS)); then
    DROP_SQL="$(mysql_exec -Nse "SELECT CONCAT('DROP ', IF(TABLE_TYPE='VIEW','VIEW','TABLE'), ' IF EXISTS ', CHAR(96), REPLACE(TABLE_NAME, CHAR(96), CONCAT(CHAR(96), CHAR(96))), CHAR(96), ';') FROM information_schema.tables WHERE table_schema='${DB_NAME}'")"
    if [[ -n "${DROP_SQL}" ]]; then
      printf 'SET FOREIGN_KEY_CHECKS=0;\n%s\nSET FOREIGN_KEY_CHECKS=1;\n' "${DROP_SQL}" | mysql_exec "${DB_NAME}"
    fi
  else
    mysql -e "DROP DATABASE \`${DB_NAME}\`;"
  fi
fi

if ((USE_EXISTING_DB_ACCESS == 0)); then
  mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD_NEW}';"
  mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD_NEW}';"
  mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
fi
gzip -dc "${BUNDLE_ROOT}/payload/database.sql.gz" | mysql_exec --default-character-set=utf8mb4 "${DB_NAME}"

set_env_value DB_CONNECTION mysql
set_env_value DB_HOST 127.0.0.1
set_env_value DB_PORT 3306
set_env_value DB_DATABASE "${DB_NAME}"
set_env_value DB_USERNAME "${DB_USER}"
set_env_value DB_PASSWORD "${DB_PASSWORD_NEW}"
set_env_value REDIS_HOST 127.0.0.1
set_env_value REDIS_PORT 6379
set_env_value CACHE_DRIVER redis
set_env_value QUEUE_CONNECTION redis
set_env_value SESSION_DRIVER redis
set_env_value APP_URL "https://${DOMAIN}"

WEB_USER="${EXISTING_WEB_USER:-}"
WEB_GROUP="${EXISTING_WEB_GROUP:-}"
if [[ -z "${WEB_USER}" ]]; then
  if ((IS_BT_PANEL)); then
    WEB_USER="www"
    WEB_GROUP="www"
  else
    WEB_USER="www-data"
    WEB_GROUP="www-data"
  fi
fi
WEB_GROUP="${WEB_GROUP:-${WEB_USER}}"
if ! id "${WEB_USER}" >/dev/null 2>&1; then
  useradd --system --home /var/www --shell /usr/sbin/nologin "${WEB_USER}"
fi
mkdir -p "${SITE_ROOT}/storage/logs" "${SITE_ROOT}/storage/framework/cache/data" \
  "${SITE_ROOT}/storage/framework/sessions" "${SITE_ROOT}/storage/framework/views" \
  "${SITE_ROOT}/bootstrap/cache"
chown -R "${WEB_USER}:${WEB_GROUP}" "${SITE_ROOT}"
chmod -R u+rwX,g+rwX "${SITE_ROOT}/storage" "${SITE_ROOT}/bootstrap/cache"
chmod 640 "${SITE_ROOT}/.env"

mkdir -p /etc/ssl/v2board
HAS_TLS=0
if [[ -s "${BUNDLE_ROOT}/certs/fullchain.pem" && -s "${BUNDLE_ROOT}/certs/privkey.pem" ]]; then
  install -m 644 "${BUNDLE_ROOT}/certs/fullchain.pem" "/etc/ssl/v2board/${DOMAIN}.fullchain.pem"
  install -m 600 "${BUNDLE_ROOT}/certs/privkey.pem" "/etc/ssl/v2board/${DOMAIN}.privkey.pem"
  HAS_TLS=1
fi

if ((IS_BT_PANEL)); then
  NGINX_SITE="/www/server/panel/vhost/nginx/${DOMAIN}.conf"
else
  NGINX_SITE="/etc/nginx/sites-available/v2board.conf"
fi

if ((IS_BT_PANEL)) && [[ -f "${NGINX_SITE}" ]]; then
  mkdir -p "${ROLLBACK_DIR}"
  cp -a "${NGINX_SITE}" "${ROLLBACK_DIR}/$(basename "${NGINX_SITE}")"
  echo "Preserving existing BT Panel nginx vhost: ${NGINX_SITE}"
else
  {
  cat <<'EOF'
log_format v2board_safe '$remote_addr - $remote_user [$time_local] "$request_method $uri $server_protocol" '
                        '$status $body_bytes_sent "$http_referer" "$http_user_agent"';

EOF
  cat <<EOF
server {
    listen 80;
    listen [::]:80;
EOF
  if ((HAS_TLS)); then
    cat <<EOF
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    ssl_certificate /etc/ssl/v2board/${DOMAIN}.fullchain.pem;
    ssl_certificate_key /etc/ssl/v2board/${DOMAIN}.privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    add_header Strict-Transport-Security "max-age=31536000" always;
EOF
  fi
  cat <<EOF
    server_name ${SERVER_NAMES};
    root ${SITE_ROOT}/public;
    index index.php index.html;
    client_max_body_size 128m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
EOF

  if ((IS_BT_PANEL)); then
    cat <<EOF
    include enable-php-${PHP_VERSION_COMPACT}.conf;
EOF
  else
    cat <<EOF
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCKET};
    }
EOF
  fi

  cat <<EOF
    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~* \.(?:css|js)$ {
        expires 1h;
        access_log off;
    }

    location ~* \.(?:gif|jpe?g|png|webp|svg|ico|woff2?)$ {
        expires 30d;
        access_log off;
    }

    access_log /var/log/nginx/v2board.access.log v2board_safe;
    error_log /var/log/nginx/v2board.error.log;
}
EOF
  } >"${NGINX_SITE}"
fi

if ((IS_BT_PANEL == 0)); then
  ln -sfn "${NGINX_SITE}" /etc/nginx/sites-enabled/v2board.conf
  rm -f /etc/nginx/sites-enabled/default
fi
"${NGINX_BIN}" -t
systemctl enable --now nginx
systemctl reload nginx

mkdir -p /var/log/supervisor
if ((IS_BT_PANEL)); then
  SUPERVISOR_CONF="/www/server/panel/plugin/supervisor/profile/v2board-horizon.ini"
else
  SUPERVISOR_CONF="/etc/supervisor/conf.d/v2board-horizon.conf"
fi
mkdir -p "$(dirname "${SUPERVISOR_CONF}")"
cat >"${SUPERVISOR_CONF}" <<EOF
[program:v2board-horizon]
command=${PHP_BIN} artisan horizon
directory=${SITE_ROOT}
user=${WEB_USER}
numprocs=1
autostart=$( ((STANDBY)) && echo false || echo true )
autorestart=true
startsecs=3
startretries=3
stopsignal=QUIT
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/log/supervisor/v2board-horizon.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
EOF

cat >/etc/cron.d/v2board-scheduler <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
$( ((STANDBY)) && echo '# ' || true )* * * * * ${WEB_USER} cd ${SITE_ROOT} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1
EOF
chmod 644 /etc/cron.d/v2board-scheduler

restore_root_crontab() {
  local source_crontab="${BUNDLE_ROOT}/system/root.crontab"
  local merged_crontab source_line line cron_script packaged_script
  [[ -s "${source_crontab}" ]] || return 0

  merged_crontab="$(mktemp)"
  crontab -l >"${merged_crontab}" 2>/dev/null || true

  if ((IS_BT_PANEL)) && [[ -d "${BUNDLE_ROOT}/system/bt-cron" ]]; then
    mkdir -p /www/server/cron
    while IFS= read -r -d '' packaged_script; do
      install -m 700 "${packaged_script}" "/www/server/cron/$(basename "${packaged_script}")"
    done < <(find "${BUNDLE_ROOT}/system/bt-cron" -maxdepth 1 -type f -print0)
  fi

  while IFS= read -r source_line || [[ -n "${source_line}" ]]; do
    [[ "${source_line}" =~ ^[[:space:]]*$ ]] && continue
    [[ "${source_line}" =~ ^[[:space:]]*# ]] && continue
    line="${source_line}"

    if [[ -n "${SOURCE_SITE_ROOT:-}" ]]; then
      line="${line//${SOURCE_SITE_ROOT}/${SITE_ROOT}}"
    fi
    if grep -Eq 'artisan[[:space:]]+schedule:run' <<<"${line}"; then
      continue
    fi

    cron_script=""
    if [[ "${line}" =~ (/www/server/cron/[A-Za-z0-9._-]+) ]]; then
      cron_script="${BASH_REMATCH[1]}"
      packaged_script="${BUNDLE_ROOT}/system/bt-cron/$(basename "${cron_script}")"
      if [[ -f "${packaged_script}" ]] && grep -Eq 'artisan[[:space:]]+schedule:run' "${packaged_script}"; then
        continue
      fi
      if ((IS_BT_PANEL == 0)); then
        echo "Skipping BT-only cron task on a non-BT target: ${source_line}"
        continue
      fi
      if [[ ! -f "${cron_script}" ]]; then
        echo "Skipping cron task because its script is unavailable: ${source_line}"
        continue
      fi
    elif ((IS_BT_PANEL == 0)) && [[ "${line}" == *'/www/server/'* ]]; then
      echo "Skipping BT-only cron task on a non-BT target: ${source_line}"
      continue
    fi

    if ! grep -Fqx -- "${line}" "${merged_crontab}"; then
      printf '%s\n' "${line}" >>"${merged_crontab}"
    fi
  done <"${source_crontab}"

  if ((STANDBY)); then
    MIGRATED_CRONTAB_PENDING="/root/v2board-migrated-crontab-${STAMP}.pending"
    install -m 600 "${merged_crontab}" "${MIGRATED_CRONTAB_PENDING}"
  else
    crontab "${merged_crontab}"
  fi
  rm -f -- "${merged_crontab}"
}

restore_root_crontab

(cd "${SITE_ROOT}" && runuser -u "${WEB_USER}" -- "${PHP_BIN}" artisan optimize:clear)
if [[ ! -e "${SITE_ROOT}/public/storage" ]]; then
  (cd "${SITE_ROOT}" && runuser -u "${WEB_USER}" -- "${PHP_BIN}" artisan storage:link) || true
fi

"${SUPERVISORCTL}" reread
"${SUPERVISORCTL}" update
if ((STANDBY == 0)); then
  "${SUPERVISORCTL}" start v2board-horizon || true
fi
systemctl restart cron

if command -v ufw >/dev/null 2>&1 && ufw status | grep -q '^Status: active'; then
  ufw allow 80/tcp
  ufw allow 443/tcp
fi

CREDENTIALS_FILE="/root/v2board-migration-credentials-${STAMP}.txt"
cat >"${CREDENTIALS_FILE}" <<EOF
Site root: ${SITE_ROOT}
Primary domain: ${DOMAIN}
Database: ${DB_NAME}
Database user: ${DB_USER}
Database password: ${DB_PASSWORD_NEW}
Standby mode: ${STANDBY}
EOF
chmod 600 "${CREDENTIALS_FILE}"

HTTP_CODE="$(curl -sS -o /dev/null -w '%{http_code}' -H "Host: ${DOMAIN}" http://127.0.0.1/ || true)"

echo
echo "Restore completed."
echo "Site root:   ${SITE_ROOT}"
echo "Domain:      ${DOMAIN}"
echo "PHP:         ${INSTALLED_PHP_VERSION}"
echo "Runtime:     $([[ ${IS_BT_PANEL} -eq 1 ]] && echo 'BT Panel' || echo 'system packages')"
echo "HTTP check:  ${HTTP_CODE}"
echo "Credentials: ${CREDENTIALS_FILE}"
if [[ -d "${ROLLBACK_DIR}" ]]; then
  echo "Rollback:    ${ROLLBACK_DIR}"
fi
if ((STANDBY)); then
  echo "Standby mode is enabled. Horizon and the Laravel scheduler are not active."
  echo "After cutover, uncomment /etc/cron.d/v2board-scheduler and run:"
  echo "  ${SUPERVISORCTL} start v2board-horizon && systemctl restart cron"
  if [[ -n "${MIGRATED_CRONTAB_PENDING}" ]]; then
    echo "Source root cron tasks are staged but inactive. Enable them at cutover with:"
    echo "  crontab ${MIGRATED_CRONTAB_PENDING}"
  fi
else
  echo "Horizon and the Laravel scheduler are active. Stop them on the old server before DNS cutover."
fi
