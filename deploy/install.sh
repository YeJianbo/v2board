#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

REPO_URL="https://github.com/YeJianbo/v2board.git"
BRANCH="main"
SITE_ROOT="/www/wwwroot/v2board"
DOMAIN=""
ADMIN_EMAIL="admin@example.com"
ARCHIVE=""
PHP_VERSION="8.1"
FORCE=0
STANDBY=0

usage() {
  cat <<'EOF'
Usage: bash install.sh --domain DOMAIN [options]

Fresh install:
  bash install.sh --domain panel.example.com --admin-email admin@example.com

Restore a migration package:
  bash install.sh --archive /root/v2board-migration.tar.gz --domain panel.example.com --force

Options:
  --repo URL           Git repository (default: YeJianbo/v2board)
  --branch NAME        Git branch or tag (default: main)
  --site-root PATH     Website directory (default: /www/wwwroot/v2board)
  --domain DOMAIN      Primary domain
  --admin-email EMAIL  Fresh-install administrator email
  --archive FILE|URL   Restore a migration package instead of a fresh install
  --php-version X.Y    Preferred PHP version (default: 8.1)
  --force              Back up and replace an existing website/database
  --standby            Restore without starting Horizon or scheduler
  -h, --help           Show this help
EOF
}

while (($#)); do
  case "$1" in
    --repo) REPO_URL="${2:?missing --repo value}"; shift 2 ;;
    --branch) BRANCH="${2:?missing --branch value}"; shift 2 ;;
    --site-root) SITE_ROOT="${2:?missing --site-root value}"; shift 2 ;;
    --domain) DOMAIN="${2:?missing --domain value}"; shift 2 ;;
    --admin-email) ADMIN_EMAIL="${2:?missing --admin-email value}"; shift 2 ;;
    --archive) ARCHIVE="${2:?missing --archive value}"; shift 2 ;;
    --php-version) PHP_VERSION="${2:?missing --php-version value}"; shift 2 ;;
    --force) FORCE=1; shift ;;
    --standby) STANDBY=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run this script as root." >&2
  exit 1
fi
if [[ -z "${DOMAIN}" ]]; then
  echo "--domain is required." >&2
  exit 1
fi
if ! [[ "${DOMAIN}" =~ ^[A-Za-z0-9.-]+$ ]]; then
  echo "Invalid domain: ${DOMAIN}" >&2
  exit 1
fi
if ! command -v apt-get >/dev/null 2>&1; then
  echo "Automatic installation currently supports Debian and Ubuntu." >&2
  exit 1
fi

IS_BT_PANEL=0
if [[ -d /www/server/panel && -x /www/server/nginx/sbin/nginx ]]; then
  IS_BT_PANEL=1
fi

export DEBIAN_FRONTEND=noninteractive
BASE_PACKAGES=(ca-certificates composer cron curl git gzip jq mariadb-client openssl rclone redis-tools rsync tar unzip)
if ! command -v mysql >/dev/null 2>&1; then
  BASE_PACKAGES+=(mariadb-server)
fi
if ! command -v redis-server >/dev/null 2>&1; then
  BASE_PACKAGES+=(redis-server)
fi
if ((IS_BT_PANEL == 0)); then
  BASE_PACKAGES+=(certbot nginx python3-certbot-nginx supervisor)
  if apt-cache show "php${PHP_VERSION}-cli" >/dev/null 2>&1; then
    BASE_PACKAGES+=("php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-gd" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-opcache" "php${PHP_VERSION}-redis" "php${PHP_VERSION}-soap" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-zip")
  else
    BASE_PACKAGES+=(php-bcmath php-cli php-curl php-fpm php-gd php-intl php-mbstring php-mysql php-opcache php-redis php-soap php-xml php-zip)
  fi
fi

apt-get update
apt-get install -y "${BASE_PACKAGES[@]}"

for service in mariadb mysql redis-server redis cron supervisor; do
  systemctl enable --now "$service" >/dev/null 2>&1 || true
done

WORK_DIR="$(mktemp -d /tmp/v2board-install.XXXXXX)"
cleanup() {
  rm -rf -- "${WORK_DIR}"
}
trap cleanup EXIT

echo "Cloning ${REPO_URL} (${BRANCH})..."
git clone --depth 1 --branch "${BRANCH}" "${REPO_URL}" "${WORK_DIR}/source"

if [[ -n "${ARCHIVE}" ]]; then
  if [[ "${ARCHIVE}" =~ ^https?:// ]]; then
    ARCHIVE_PATH="${WORK_DIR}/$(basename "${ARCHIVE%%\?*}")"
    curl -fL --retry 3 --connect-timeout 10 "${ARCHIVE}" -o "${ARCHIVE_PATH}"
  else
    ARCHIVE_PATH="$(readlink -f "${ARCHIVE}")"
  fi
  if [[ ! -f "${ARCHIVE_PATH}" ]]; then
    echo "Migration archive was not found: ${ARCHIVE}" >&2
    exit 1
  fi

  RESTORE_ARGS=(--archive "${ARCHIVE_PATH}" --site-root "${SITE_ROOT}" --domain "${DOMAIN}" --php-version "${PHP_VERSION}")
  ((FORCE)) && RESTORE_ARGS+=(--force)
  ((STANDBY)) && RESTORE_ARGS+=(--standby)
  bash "${WORK_DIR}/source/deploy/migration/restore.sh" "${RESTORE_ARGS[@]}"
  exit $?
fi

if [[ -e "${SITE_ROOT}" && -n "$(find "${SITE_ROOT}" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]]; then
  if ((FORCE == 0)); then
    echo "Target directory is not empty: ${SITE_ROOT}. Use --force to replace it." >&2
    exit 1
  fi
  mv "${SITE_ROOT}" "${SITE_ROOT}.pre-install-$(date +%Y%m%d-%H%M%S)"
fi

mkdir -p "$(dirname "${SITE_ROOT}")"
mv "${WORK_DIR}/source" "${SITE_ROOT}"

if ((IS_BT_PANEL)); then
  PHP_BIN="/www/server/php/${PHP_VERSION//./}/bin/php"
  COMPOSER_BIN="$(command -v composer || true)"
  NGINX_BIN="/www/server/nginx/sbin/nginx"
  SUPERVISORCTL="/www/server/panel/pyenv/bin/supervisorctl"
  WEB_USER="www"
  WEB_GROUP="www"
  PHP_SOCKET="/tmp/php-cgi-${PHP_VERSION//./}.sock"
else
  PHP_BIN="$(command -v "php${PHP_VERSION}" || command -v php)"
  COMPOSER_BIN="$(command -v composer)"
  NGINX_BIN="$(command -v nginx)"
  SUPERVISORCTL="$(command -v supervisorctl)"
  WEB_USER="www-data"
  WEB_GROUP="www-data"
  PHP_FPM_SERVICE="$(systemctl list-unit-files --type=service --no-legend | awk '/php[0-9.]+-fpm\.service/ {print $1}' | sort -V | tail -n 1)"
  if [[ -z "${PHP_FPM_SERVICE}" ]]; then
    echo "PHP-FPM service was not found." >&2
    exit 1
  fi
  systemctl enable --now "${PHP_FPM_SERVICE}"
  systemctl enable --now nginx
  INSTALLED_PHP_VERSION="$(${PHP_BIN} -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
  PHP_SOCKET="$(find /run/php -maxdepth 1 -type s -name "php${INSTALLED_PHP_VERSION}*-fpm.sock" 2>/dev/null | head -n 1 || true)"
fi

for executable in "${PHP_BIN}" "${COMPOSER_BIN}" "${NGINX_BIN}" "${SUPERVISORCTL}"; do
  if [[ -z "${executable}" || ! -x "${executable}" ]]; then
    echo "Missing runtime executable: ${executable:-unknown}" >&2
    exit 1
  fi
done
if [[ -z "${PHP_SOCKET}" ]]; then
  echo "PHP-FPM socket was not found." >&2
  exit 1
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
  FPM_CONFIG="/www/server/php/${PHP_VERSION//./}/etc/php-fpm.conf"
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
fi

cd "${SITE_ROOT}"
COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER_BIN}" install --no-dev --prefer-dist --no-interaction --optimize-autoloader

DB_NAME="v2board"
DB_USER="v2board_app"
DB_PASSWORD="$(openssl rand -hex 24)"
if mysql -Nse "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}'" | grep -qx "${DB_NAME}"; then
  TABLE_COUNT="$(mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'")"
  if [[ "${TABLE_COUNT}" != "0" && "${FORCE}" -eq 0 ]]; then
    echo "Database ${DB_NAME} is not empty. Use --force to replace it." >&2
    exit 1
  fi
  if [[ "${TABLE_COUNT}" != "0" ]]; then
    mysqldump --single-transaction --quick --routines --triggers --events --no-tablespaces "${DB_NAME}" | gzip -1 >"/root/${DB_NAME}.pre-install-$(date +%Y%m%d-%H%M%S).sql.gz"
    mysql -e "DROP DATABASE \`${DB_NAME}\`;"
  fi
fi
mysql -e "CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}'; ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}'; GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"

INSTALL_LOG="/root/v2board-install-$(date +%Y%m%d-%H%M%S).log"
printf '127.0.0.1\n%s\n%s\n%s\n%s\n' "${DB_NAME}" "${DB_USER}" "${DB_PASSWORD}" "${ADMIN_EMAIL}" | "${PHP_BIN}" artisan v2board:install | tee "${INSTALL_LOG}"
if [[ ! -s .env ]] || ! mysql "${DB_NAME}" -Nse 'SELECT COUNT(*) FROM v2_user' >/dev/null 2>&1; then
  echo "Application installation did not complete. See ${INSTALL_LOG}." >&2
  exit 1
fi

set_env_value() {
  local key="$1" value="$2" tmp
  tmp="$(mktemp)"
  awk -v key="${key}" -v value="${value}" '
    BEGIN { found=0 }
    index($0, key "=") == 1 { print key "=" value; found=1; next }
    { print }
    END { if (!found) print key "=" value }
  ' .env >"${tmp}"
  cp "${tmp}" .env
  rm -f "${tmp}"
}

set_env_value APP_ENV production
set_env_value APP_DEBUG false
set_env_value APP_URL "https://${DOMAIN}"
set_env_value CACHE_DRIVER redis
set_env_value QUEUE_CONNECTION redis
set_env_value SESSION_DRIVER redis
set_env_value LOG_CHANNEL stack

"${PHP_BIN}" artisan migrate:install >/dev/null 2>&1 || true
BATCH="$(mysql "${DB_NAME}" -Nse 'SELECT COALESCE(MAX(batch),0)+1 FROM migrations')"
for migration_file in database/migrations/*.php; do
  migration="$(basename "${migration_file}" .php)"
  MYSQL_PWD="${DB_PASSWORD}" mysql -u "${DB_USER}" "${DB_NAME}" -e "INSERT IGNORE INTO migrations (migration,batch) VALUES ('${migration}',${BATCH});"
done

mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
chown -R "${WEB_USER}:${WEB_GROUP}" "${SITE_ROOT}"
chmod -R u+rwX,g+rwX storage bootstrap/cache
chmod 640 .env
runuser -u "${WEB_USER}" -- "${PHP_BIN}" artisan optimize:clear
runuser -u "${WEB_USER}" -- "${PHP_BIN}" artisan config:cache
runuser -u "${WEB_USER}" -- "${PHP_BIN}" artisan storage:link >/dev/null 2>&1 || true

if ((IS_BT_PANEL)); then
  NGINX_SITE="/www/server/panel/vhost/nginx/${DOMAIN}.conf"
  SUPERVISOR_CONF="/www/server/panel/plugin/supervisor/profile/v2board-horizon.ini"
else
  NGINX_SITE="/etc/nginx/sites-available/v2board.conf"
  SUPERVISOR_CONF="/etc/supervisor/conf.d/v2board-horizon.conf"
fi
mkdir -p "$(dirname "${NGINX_SITE}")" "$(dirname "${SUPERVISOR_CONF}")" /var/log/supervisor

cat >"${NGINX_SITE}" <<EOF
log_format v2board_safe '\$remote_addr - \$remote_user [\$time_local] "\$request_method \$uri \$server_protocol" '
                        '\$status \$body_bytes_sent "\$http_referer" "\$http_user_agent"';

server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${SITE_ROOT}/public;
    index index.php index.html;
    client_max_body_size 128m;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_connect_timeout 10s;
        fastcgi_read_timeout 60s;
        fastcgi_pass unix:${PHP_SOCKET};
    }
    location ~ /\.(?!well-known).* { deny all; }
    location ~* \.(?:css|js)$ { expires 1h; access_log off; }
    location ~* \.(?:gif|jpe?g|png|webp|svg|ico|woff2?)$ { expires 30d; access_log off; }
    access_log /var/log/nginx/v2board.access.log v2board_safe;
    error_log /var/log/nginx/v2board.error.log;
}
EOF
if ((IS_BT_PANEL == 0)); then
  ln -sfn "${NGINX_SITE}" /etc/nginx/sites-enabled/v2board.conf
  rm -f /etc/nginx/sites-enabled/default
fi
"${NGINX_BIN}" -t
systemctl reload nginx
if ((IS_BT_PANEL == 0)); then
  certbot --nginx --non-interactive --agree-tos --email "${ADMIN_EMAIL}" -d "${DOMAIN}" --redirect || echo "TLS issuance was skipped; verify DNS and run certbot later."
fi

cat >"${SUPERVISOR_CONF}" <<EOF
[program:v2board-horizon]
command=${PHP_BIN} artisan horizon
directory=${SITE_ROOT}
user=${WEB_USER}
numprocs=1
autostart=true
autorestart=true
startsecs=3
stopsignal=QUIT
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/log/supervisor/v2board-horizon.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
EOF
"${SUPERVISORCTL}" reread
"${SUPERVISORCTL}" update

cat >/etc/cron.d/v2board-scheduler <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * ${WEB_USER} cd ${SITE_ROOT} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1
EOF
chmod 644 /etc/cron.d/v2board-scheduler
systemctl restart cron

if command -v ufw >/dev/null 2>&1 && ufw status | grep -q '^Status: active'; then
  ufw allow 80/tcp
  ufw allow 443/tcp
fi

CREDENTIALS_FILE="/root/v2board-credentials-$(date +%Y%m%d-%H%M%S).txt"
{
  echo "Domain: ${DOMAIN}"
  echo "Site root: ${SITE_ROOT}"
  echo "Database: ${DB_NAME}"
  echo "Database user: ${DB_USER}"
  echo "Database password: ${DB_PASSWORD}"
  echo "Administrator email: ${ADMIN_EMAIL}"
  grep -E '管理员密码|管理面板' "${INSTALL_LOG}" || true
} >"${CREDENTIALS_FILE}"
chmod 600 "${CREDENTIALS_FILE}"

HTTP_CODE="$(curl -sS -o /dev/null -w '%{http_code}' -H "Host: ${DOMAIN}" http://127.0.0.1/ || true)"
echo "Installation completed. HTTP ${HTTP_CODE}"
echo "Credentials: ${CREDENTIALS_FILE}"
