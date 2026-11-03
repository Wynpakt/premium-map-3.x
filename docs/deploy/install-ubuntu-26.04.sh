#!/bin/bash
# Install the Landkarte the way production runs it, on a fresh Ubuntu 26.04 server:
# Apache + PHP 8.5 + Phalcon 5.23, MySQL 8.4, Photon 0.5 for DE/AT/CH, cron.
#
# This is not the Compose dev stack. Dev Compose bind-mounts the source and uses
# the database name "cola". Production paths match docs/cron/landkarte.cron.
#
# From a checkout on the server:
#   sudo I_UNDERSTAND=1 bash docs/deploy/install-ubuntu-26.04.sh
#
# Optional:
#   APP_DIR=/var/www/api.landkarte.premium-cola.de
#   MAP_DIR=/var/www/landkarte.premium-cola.de
#   API_HOST=api.landkarte.premium-cola.de
#   MAP_HOST=landkarte.premium-cola.de
#   DB_NAME=landkarte
#   DB_USER=landkarte
#   SKIP_PHOTON=1          skip the index download (about 22 GB installed, 30 GB free)
#   SKIP_FRONTEND=1        skip cloning and building the Angular map
#   FRONTEND_REPO=https://github.com/Wynpakt/premium-map-frontend.git
#   FRONTEND_REF=master
#   ENABLE_TLS=1 TLS_EMAIL=ops@example.com
#   MAP_MODE=public|internal   default public; a later run keeps /etc/landkarte/mode
#   MAP_USER=... MAP_PASSWORD=...   required the first time MAP_MODE=internal
#
# Collmex and SMTP stay empty. Fill them in app/config/config.php afterwards.
# The database password is written to /etc/landkarte/db.env (mode 600).
# An internal map asks for MAP_USER before Apache serves the API or the map.
# The password lives in /etc/landkarte/htpasswd (mode 640), not in the repo.
set -euo pipefail

render_auth() {
  case "$1" in
    public)
      printf '%s\n' '        Require all granted'
      ;;
    internal)
      printf '%s\n' \
        '        AuthType Basic' \
        '        AuthName "Landkarte"' \
        '        AuthUserFile /etc/landkarte/htpasswd' \
        '        Require valid-user'
      ;;
    *)
      echo "MAP_MODE must be public or internal (got ${1})." >&2
      return 1
      ;;
  esac
}

apply_auth() {
  local src="$1" dst="$2" snippet
  snippet="$(mktemp)"
  render_auth "$MAP_MODE" > "$snippet"
  awk '
    NR==FNR { block = block $0 ORS; next }
    index($0, "__AUTH__") { printf "%s", block; next }
    { print }
  ' "$snippet" "$src" > "$dst"
  rm -f "$snippet"
}

# The internal map calls its own host. Apache forwards the API paths and
# keeps the single basic-auth login. The public map calls its API host
# directly, so this block stays empty there.
render_api_proxy() {
  local mode="$1" host="$2"
  if [ "$mode" != "internal" ]; then
    return 0
  fi
  cat << EOF
    SSLProxyEngine On
    <Location /item/>
        AuthType Basic
        AuthName "Landkarte"
        AuthUserFile /etc/landkarte/htpasswd
        Require valid-user
        ProxyPass https://${host}/item/
        ProxyPassReverse https://${host}/item/
    </Location>
    <Location /search>
        AuthType Basic
        AuthName "Landkarte"
        AuthUserFile /etc/landkarte/htpasswd
        Require valid-user
        ProxyPass https://${host}/search
        ProxyPassReverse https://${host}/search
    </Location>
    RewriteEngine On
    RewriteCond %{REQUEST_METHOD} =POST
    RewriteRule ^/feedback(/report)?\$ https://${host}/feedback\$1 [P,L,NE]
EOF
}

apply_api_proxy() {
  local src="$1" dst="$2" snippet
  snippet="$(mktemp)"
  render_api_proxy "$MAP_MODE" "$API_HOST" > "$snippet"
  if [ ! -s "$snippet" ]; then
    grep -v '^__API_PROXY__$' "$src" > "$dst"
  else
    awk '
      NR==FNR { block = block $0 ORS; next }
      $0 == "__API_PROXY__" { printf "%s", block; next }
      { print }
    ' "$snippet" "$src" > "$dst"
  fi
  rm -f "$snippet"
}

if [ "${1:-}" = "--self-check" ]; then
  public_auth="$(render_auth public)"
  case "$public_auth" in
    *"Require all granted"*) ;;
    *) echo "public map did not stay open" >&2; exit 1 ;;
  esac
  case "$public_auth" in
    *AuthType*) echo "public map asked for a password" >&2; exit 1 ;;
  esac
  internal_auth="$(render_auth internal)"
  case "$internal_auth" in
    *"AuthType Basic"*"AuthUserFile /etc/landkarte/htpasswd"*"Require valid-user"*) ;;
    *) echo "internal map has no password gate" >&2; exit 1 ;;
  esac
  if render_auth other >/dev/null 2>&1; then
    echo "unknown MAP_MODE was accepted" >&2
    exit 1
  fi
  here="$(cd "$(dirname "$0")" && pwd)"
  work="$(mktemp -d)"
  sed -e 's|__API_HOST__|api.internal.example|g' -e 's|__APP_DIR__|/var/www/api|g' \
    "${here}/apache-api.conf" > "$work/api.in"
  sed -e 's|__MAP_HOST__|map.internal.example|g' -e 's|__MAP_DIR__|/var/www/map|g' \
    "${here}/apache-map.conf" > "$work/map.in"
  MAP_MODE=internal
  API_HOST=api.internal.example
  apply_auth "$work/api.in" "$work/api.conf"
  apply_auth "$work/map.in" "$work/map.auth"
  apply_api_proxy "$work/map.auth" "$work/map.conf"
  grep -q 'AuthType Basic' "$work/api.conf"
  grep -q 'AuthType Basic' "$work/map.conf"
  grep -q 'api.internal.example' "$work/api.conf"
  grep -q 'map.internal.example' "$work/map.conf"
  grep -q 'ProxyPass https://api.internal.example/item/' "$work/map.conf"
  if grep -q '__AUTH__\|__API_PROXY__' "$work/api.conf" "$work/map.conf"; then
    echo "auth placeholder left in a vhost" >&2
    exit 1
  fi
  MAP_MODE=public
  apply_auth "$work/api.in" "$work/api.conf"
  apply_api_proxy "$work/map.auth" "$work/map.conf"
  if grep -q 'AuthType' "$work/api.conf"; then
    echo "public vhost asked for a password" >&2
    exit 1
  fi
  if grep -q 'ProxyPass\|__API_PROXY__' "$work/map.conf"; then
    echo "public map forwarded the API" >&2
    exit 1
  fi
  grep -q 'Require all granted' "$work/api.conf"
  rm -rf "$work"
  echo "audience self-check ok"
  exit 0
fi

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root." >&2
  exit 1
fi

if [ "${I_UNDERSTAND:-}" != "1" ]; then
  echo "Refusing to install system packages without I_UNDERSTAND=1." >&2
  echo "This installs Apache, MySQL, PHP 8.5, Phalcon 5.23, the Angular map and Photon." >&2
  echo "Photon indexes need about 30 GB free under /var. Prefer 8 GB+ RAM for the DE index." >&2
  exit 1
fi

. /etc/os-release
if [ "${VERSION_ID:-}" != "26.04" ]; then
  echo "This script targets Ubuntu 26.04 (found ${VERSION_ID:-unknown})." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$(cd "${SCRIPT_DIR}/../.." && pwd)"
APP_DIR="${APP_DIR:-/var/www/api.landkarte.premium-cola.de}"
MAP_DIR="${MAP_DIR:-/var/www/landkarte.premium-cola.de}"
FRONTEND_SRC="${FRONTEND_SRC:-/var/src/premium-map-frontend}"
FRONTEND_REPO="${FRONTEND_REPO:-https://github.com/Wynpakt/premium-map-frontend.git}"
FRONTEND_REF="${FRONTEND_REF:-master}"
API_HOST="${API_HOST:-api.landkarte.premium-cola.de}"
MAP_HOST="${MAP_HOST:-landkarte.premium-cola.de}"
DB_NAME="${DB_NAME:-landkarte}"
DB_USER="${DB_USER:-landkarte}"
if ! [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "DB_NAME and DB_USER may only contain letters, digits and underscore." >&2
  exit 1
fi
SKIP_PHOTON="${SKIP_PHOTON:-0}"
SKIP_FRONTEND="${SKIP_FRONTEND:-0}"
ENABLE_TLS="${ENABLE_TLS:-0}"
if [ -z "${MAP_MODE:-}" ] && [ -f /etc/landkarte/mode ]; then
  MAP_MODE="$(tr -d '[:space:]' < /etc/landkarte/mode)"
fi
MAP_MODE="${MAP_MODE:-public}"
render_auth "$MAP_MODE" >/dev/null
if [ "$MAP_MODE" = "internal" ]; then
  if [ -n "${MAP_PASSWORD:-}" ] || [ -n "${MAP_USER:-}" ]; then
    if [ -z "${MAP_USER:-}" ] || [ -z "${MAP_PASSWORD:-}" ]; then
      echo "Internal map needs both MAP_USER and MAP_PASSWORD." >&2
      exit 1
    fi
    if ! [[ "$MAP_USER" =~ ^[A-Za-z0-9._@-]+$ ]]; then
      echo "MAP_USER may only contain letters, digits, and . _ @ -." >&2
      exit 1
    fi
  elif [ ! -s /etc/landkarte/htpasswd ]; then
    echo "Internal map needs MAP_USER and MAP_PASSWORD on the first run." >&2
    exit 1
  fi
fi

export DEBIAN_FRONTEND=noninteractive

mem_mb="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
disk_gb="$(df -BG --output=avail /var | tail -1 | tr -dc '0-9')"
echo "RAM ${mem_mb} MB, free on /var: ${disk_gb} GB"
# Germany's 9.2 GB archive and its ~18 GB index sit on disk together during extract.
PHOTON_MIN_FREE_GB=30
# Cold Germany load took about 8 minutes. 180 * 5s also covers Austria and Switzerland.
PHOTON_READY_TRIES=180
if [ "$disk_gb" -lt "$PHOTON_MIN_FREE_GB" ] && [ "$SKIP_PHOTON" != "1" ]; then
  echo "Need ${PHOTON_MIN_FREE_GB} GB free on /var for the Photon indexes (have ${disk_gb} GB)." >&2
  echo "Set SKIP_PHOTON=1 to install the API without geocoding data." >&2
  exit 1
fi
if [ "$mem_mb" -lt 4000 ] && [ "$SKIP_PHOTON" != "1" ] && [ "${FORCE_PHOTON:-0}" != "1" ]; then
  echo "Photon needs more RAM than this host reports (${mem_mb} MB)." >&2
  echo "Use SKIP_PHOTON=1, or FORCE_PHOTON=1 to try anyway (likely to OOM)." >&2
  exit 1
fi
if [ "$mem_mb" -lt 12000 ] && [ "$SKIP_PHOTON" != "1" ]; then
  echo "Warning: under 12 GB RAM. Photon heaps will be reduced."
fi

echo "Installing packages"
apt-get update
apt-get install -y \
  apache2 \
  libapache2-mod-php8.5 \
  php8.5-cli \
  php8.5-curl \
  php8.5-mbstring \
  php8.5-mysql \
  php8.5-xml \
  php8.5-zip \
  php8.5-dev \
  php-pear \
  build-essential \
  composer \
  mysql-server \
  openjdk-11-jre-headless \
  git \
  curl \
  apache2-utils \
  ca-certificates \
  bzip2 \
  rsync \
  unzip

# php -v stays 8.5.4. The fix for CVE-2026-109618 and CVE-2026-109619
# is this package revision (USN-8948-1), not a newer upstream string.
UBUNTU_PHP_PACKAGE_FLOOR=8.5.4-0ubuntu1.5
php_pkg="$(dpkg-query -W -f '${Version}' libapache2-mod-php8.5)"
if ! dpkg --compare-versions "$php_pkg" ge "$UBUNTU_PHP_PACKAGE_FLOOR"; then
  systemctl stop apache2 || true
  echo "libapache2-mod-php8.5 ${php_pkg} is older than ${UBUNTU_PHP_PACKAGE_FLOOR} (CVE-2026-109618, CVE-2026-109619)." >&2
  exit 1
fi

# Phalcon on this host returns empty replies when PCRE JIT cannot allocate.
printf 'pcre.jit=0\n' > /etc/php/8.5/mods-available/pcre-jit.ini
phpenmod -v 8.5 -s ALL pcre-jit

if ! php -m | grep -qi '^phalcon$'; then
  echo "Building Phalcon 5.23"
  pecl install phalcon-5.23.0
  echo 'extension=phalcon.so' > /etc/php/8.5/mods-available/phalcon.ini
  phpenmod -v 8.5 -s ALL phalcon
fi
php -m | grep -qi '^phalcon$'
php -r 'echo "PHP ".PHP_VERSION.", Phalcon ".phpversion("phalcon")."\n";'

systemctl enable --now mysql
systemctl enable --now apache2

if [ "$SRC" != "$APP_DIR" ]; then
  echo "Copying application to ${APP_DIR}"
  mkdir -p "$APP_DIR"
  rsync -a \
    --exclude '.git/' \
    --exclude 'app/config/config.php' \
    --exclude 'vendor/' \
    --exclude 'app/cache/' \
    "${SRC}/" "${APP_DIR}/"
fi

install -d -o www-data -g www-data "${APP_DIR}/app/cache"
install -d -o www-data -g www-data "$MAP_DIR"

if [ "$SKIP_FRONTEND" != "1" ]; then
  echo "Installing Node 24 for the Angular map build"
  if ! command -v node >/dev/null || ! node -v | grep -qE '^v2[4-9]\.'; then
    curl -fsSL https://deb.nodesource.com/setup_24.x | bash -
    apt-get install -y nodejs
  fi
  node -v
  npm -v

  echo "Fetching frontend ${FRONTEND_REPO} (${FRONTEND_REF})"
  install -d "$(dirname "$FRONTEND_SRC")"
  if [ -d "${FRONTEND_SRC}/.git" ]; then
    git -C "$FRONTEND_SRC" fetch --depth 1 origin "$FRONTEND_REF"
    git -C "$FRONTEND_SRC" checkout -B "$FRONTEND_REF" "FETCH_HEAD"
  else
    rm -rf "$FRONTEND_SRC"
    git clone --depth 1 --branch "$FRONTEND_REF" "$FRONTEND_REPO" "$FRONTEND_SRC"
  fi

  echo "Building frontend into ${MAP_DIR}"
  (
    cd "$FRONTEND_SRC"
    npm ci
    if [ "$MAP_MODE" = "internal" ]; then
      npx ng build --configuration=internal
    else
      npx ng build --configuration=production
    fi
  )
  if [ ! -f "${FRONTEND_SRC}/dist/index.html" ]; then
    echo "Frontend build produced no dist/index.html" >&2
    exit 1
  fi
  rsync -a --delete \
    --exclude '.git/' \
    "${FRONTEND_SRC}/dist/" "${MAP_DIR}/"
  chown -R www-data:www-data "$MAP_DIR"
else
  echo "SKIP_FRONTEND=1 — leaving ${MAP_DIR} as-is"
fi

if [ ! -f "${APP_DIR}/app/config/config.php" ]; then
  DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)"
  install -d -m 700 /etc/landkarte
  # Keep the tight umask on the password file only. A leaked umask 077 makes the
  # Photon jar mode 600, and the photon user cannot read it.
  (
    umask 077
    cat > /etc/landkarte/db.env <<EOF
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
EOF
    chmod 600 /etc/landkarte/db.env
  )
  mysql --batch <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8 COLLATE utf8_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
  CONFIG_SRC="${APP_DIR}/app/config/config.default.php" \
  CONFIG_DST="${APP_DIR}/app/config/config.php" \
  DB_USER="$DB_USER" DB_PASS="$DB_PASS" DB_NAME="$DB_NAME" \
  php <<'PHP'
<?php
$src = getenv('CONFIG_SRC');
$dst = getenv('CONFIG_DST');
$text = file_get_contents($src);
$repl = array(
    "'username'    => ''" => "'username'    => " . var_export(getenv('DB_USER'), true),
    "'password'    => ''" => "'password'    => " . var_export(getenv('DB_PASS'), true),
    "'dbname'      => ''" => "'dbname'      => " . var_export(getenv('DB_NAME'), true),
);
foreach ($repl as $from => $to) {
    if (strpos($text, $from) === false) {
        fwrite(STDERR, "config template missing: {$from}\n");
        exit(1);
    }
    $text = str_replace($from, $to, $text);
}
file_put_contents($dst, $text);
PHP
  echo "Wrote ${APP_DIR}/app/config/config.php and /etc/landkarte/db.env"
else
  echo "Keeping existing ${APP_DIR}/app/config/config.php"
  mysql --batch -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8 COLLATE utf8_general_ci;"
fi

MAP_MODE_VALUE="$MAP_MODE" \
CONFIG_DST="${APP_DIR}/app/config/config.php" \
php <<'PHP'
<?php
require_once dirname(getenv('CONFIG_DST')) . '/../lib/MapAudience.php';
$text = file_get_contents(getenv('CONFIG_DST'));
$next = MapAudience::patchConfigText($text, getenv('MAP_MODE_VALUE'));
if ($next === false) {
    fwrite(STDERR, "config.php has no collmex key, audience was not written\n");
    exit(1);
}
file_put_contents(getenv('CONFIG_DST'), $next);
PHP
echo "Audience in config.php: ${MAP_MODE}"

chown -R www-data:www-data "$APP_DIR" "$MAP_DIR"
chmod 640 "${APP_DIR}/app/config/config.php"

install -d -o www-data -g www-data /var/cache/composer
runuser -u www-data -- env COMPOSER_HOME=/var/cache/composer \
  composer install --no-interaction --no-dev --working-dir "$APP_DIR"

item_table="$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='item'")"
if [ "$item_table" = "0" ]; then
  echo "Loading schema into ${DB_NAME}"
  mysql "$DB_NAME" < "${APP_DIR}/docs/database/schema.sql"
fi
runuser -u www-data -- php "${APP_DIR}/app/migrate.php"

install -d -o root -g www-data -m 750 /etc/landkarte
printf '%s\n' "$MAP_MODE" > /etc/landkarte/mode
chmod 644 /etc/landkarte/mode
if [ "$MAP_MODE" = "internal" ] && [ -n "${MAP_PASSWORD:-}" ]; then
  printf '%s' "$MAP_PASSWORD" | htpasswd -B -i -c /etc/landkarte/htpasswd "$MAP_USER"
  chown root:www-data /etc/landkarte/htpasswd
  chmod 640 /etc/landkarte/htpasswd
  echo "Internal login written to /etc/landkarte/htpasswd"
fi

a2enmod rewrite auth_basic proxy proxy_http >/dev/null
api_tmp="$(mktemp)"
map_tmp="$(mktemp)"
sed \
  -e "s|__API_HOST__|${API_HOST}|g" \
  -e "s|__APP_DIR__|${APP_DIR}|g" \
  "${SCRIPT_DIR}/apache-api.conf" > "$api_tmp"
sed \
  -e "s|__MAP_HOST__|${MAP_HOST}|g" \
  -e "s|__MAP_DIR__|${MAP_DIR}|g" \
  "${SCRIPT_DIR}/apache-map.conf" > "$map_tmp"
apply_auth "$api_tmp" /etc/apache2/sites-available/landkarte-api.conf
map_auth="$(mktemp)"
apply_auth "$map_tmp" "$map_auth"
apply_api_proxy "$map_auth" /etc/apache2/sites-available/landkarte-map.conf
rm -f "$api_tmp" "$map_tmp" "$map_auth"
a2dissite 000-default >/dev/null || true
a2ensite landkarte-api.conf landkarte-map.conf >/dev/null
apachectl configtest
systemctl restart apache2

install -d -o www-data -g www-data /var/log/landkarte
sed "s|/var/www/api.landkarte.premium-cola.de|${APP_DIR}|g" \
  "${APP_DIR}/docs/cron/landkarte.cron" > /etc/cron.d/landkarte
chmod 644 /etc/cron.d/landkarte
install -m 644 "${SCRIPT_DIR}/logrotate-landkarte" /etc/logrotate.d/landkarte

if [ "$SKIP_PHOTON" != "1" ]; then
  echo "Installing Photon 0.5 (DE, AT, CH)"
  id photon >/dev/null 2>&1 || useradd --system --home-dir /var/lib/photon --shell /usr/sbin/nologin photon
  install -d -o photon -g photon /opt/photon /var/lib/photon /etc/photon
  if [ ! -f /opt/photon/photon-0.5.0.jar ]; then
    curl -fL --retry 5 --retry-delay 5 \
      -o /opt/photon/photon-0.5.0.jar \
      https://github.com/komoot/photon/releases/download/0.5.0/photon-0.5.0.jar
  fi
  chmod 644 /opt/photon/photon-0.5.0.jar
  install -m 755 "${SCRIPT_DIR}/photon/run.sh" /opt/photon/run.sh
  install -m 755 "${SCRIPT_DIR}/photon/download-archive.sh" /opt/photon/download-archive.sh
  install -m 755 "${SCRIPT_DIR}/photon/fetch-index.sh" /opt/photon/fetch-index.sh
  install -m 755 "${SCRIPT_DIR}/photon/refresh-indexes.sh" /opt/photon/refresh-indexes.sh
  install -m 644 "${SCRIPT_DIR}/photon/photon@.service" /etc/systemd/system/photon@.service
  if [ "$mem_mb" -lt 6000 ]; then
    cat > /etc/photon/de.env <<'EOF'
PHOTON_DATA=/var/lib/photon/de
PHOTON_PORT=2322
PHOTON_XMS=512m
PHOTON_XMX=1g
EOF
    cat > /etc/photon/at.env <<'EOF'
PHOTON_DATA=/var/lib/photon/at
PHOTON_PORT=2323
PHOTON_XMS=256m
PHOTON_XMX=512m
EOF
    cat > /etc/photon/ch.env <<'EOF'
PHOTON_DATA=/var/lib/photon/ch
PHOTON_PORT=2324
PHOTON_XMS=256m
PHOTON_XMX=512m
EOF
  elif [ "$mem_mb" -lt 12000 ]; then
    sed -e 's/^PHOTON_XMS=2g/PHOTON_XMS=1g/' -e 's/^PHOTON_XMX=4g/PHOTON_XMX=2g/' \
      "${SCRIPT_DIR}/photon/de.env" > /etc/photon/de.env
    install -m 644 "${SCRIPT_DIR}/photon/at.env" /etc/photon/at.env
    install -m 644 "${SCRIPT_DIR}/photon/ch.env" /etc/photon/ch.env
  else
    install -m 644 "${SCRIPT_DIR}/photon/de.env" /etc/photon/de.env
    install -m 644 "${SCRIPT_DIR}/photon/at.env" /etc/photon/at.env
    install -m 644 "${SCRIPT_DIR}/photon/ch.env" /etc/photon/ch.env
  fi
  for cc in de at ch; do
    bash "${SCRIPT_DIR}/photon/fetch-index.sh" "$cc" "/var/lib/photon/${cc}"
    chown -R photon:photon "/var/lib/photon/${cc}"
  done
  systemctl daemon-reload
  systemctl enable --now photon@de photon@at photon@ch
  echo "Waiting up to 15 minutes for Photon on 2322, 2323 and 2324"
  ready=0
  for _ in $(seq 1 "$PHOTON_READY_TRIES"); do
    if curl -sf -o /dev/null --max-time 2 "http://127.0.0.1:2322/api?limit=1&lang=de&q=Berlin" \
      && curl -sf -o /dev/null --max-time 2 "http://127.0.0.1:2323/api?limit=1&lang=de&q=Wien" \
      && curl -sf -o /dev/null --max-time 2 "http://127.0.0.1:2324/api?limit=1&lang=de&q=Bern"; then
      ready=1
      break
    fi
    sleep 5
  done
  if [ "$ready" != "1" ]; then
    echo "Photon is not answering yet. Check: journalctl -u photon@de -u photon@at -u photon@ch -e" >&2
  else
    echo "Photon DE, AT and CH are answering."
  fi
fi

if command -v ufw >/dev/null && ufw status | grep -q 'Status: active'; then
  ufw allow 80/tcp
  ufw allow 443/tcp
fi

if [ "$ENABLE_TLS" = "1" ]; then
  if [ -z "${TLS_EMAIL:-}" ]; then
    echo "ENABLE_TLS=1 needs TLS_EMAIL." >&2
    exit 1
  fi
  apt-get install -y certbot python3-certbot-apache
  certbot --apache --non-interactive --agree-tos -m "$TLS_EMAIL" \
    -d "$API_HOST" -d "$MAP_HOST"
fi

echo
echo "API smoke check:"
if [ "$MAP_MODE" = "internal" ]; then
  api_code="$(curl -sS -o /dev/null -w '%{http_code}' -H "Host: ${API_HOST}" \
    "http://127.0.0.1/item/list?types[]=laeden&countries[]=DE&products[]=cola" || true)"
  map_code="$(curl -sS -o /dev/null -w '%{http_code}' -H "Host: ${MAP_HOST}" \
    "http://127.0.0.1/" || true)"
  echo "API without password: HTTP ${api_code} (401 means the gate is up)"
  echo "Map without password: HTTP ${map_code} (401 means the gate is up)"
else
  curl -sS -H "Host: ${API_HOST}" \
    "http://127.0.0.1/item/list?types[]=laeden&countries[]=DE&products[]=cola" || true
  echo
fi
echo
echo "Next:"
echo "  1. Put Collmex user, password and customer_id into ${APP_DIR}/app/config/config.php"
echo "  2. Once DNS points here: ENABLE_TLS=1 TLS_EMAIL=... I_UNDERSTAND=1 bash $0"
echo "  3. Rebuild the map later: I_UNDERSTAND=1 SKIP_PHOTON=1 bash $0"
echo "Mode on this host: ${MAP_MODE} (/etc/landkarte/mode). A later run keeps it."
echo "A new public map:  MAP_MODE=public  I_UNDERSTAND=1 bash $0"
echo "A new internal map: MAP_MODE=internal MAP_USER=... MAP_PASSWORD=... I_UNDERSTAND=1 bash $0"
echo "Database password: /etc/landkarte/db.env"
echo "Frontend source: ${FRONTEND_SRC} → ${MAP_DIR}"
echo "Geocoding: php ${APP_DIR}/app/cli.php update geo   (cron does this hourly)"
