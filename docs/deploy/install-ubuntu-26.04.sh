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
#   SKIP_PHOTON=1          skip the index download (~15 GB under /var/lib/photon)
#   SKIP_FRONTEND=1        skip cloning and building the Angular map
#   FRONTEND_REPO=https://github.com/Wynpakt/premium-map-frontend.git
#   FRONTEND_REF=master
#   ENABLE_TLS=1 TLS_EMAIL=ops@example.com
#
# Collmex and SMTP stay empty. Fill them in app/config/config.php afterwards.
# The database password is written to /etc/landkarte/db.env (mode 600).
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root." >&2
  exit 1
fi

if [ "${I_UNDERSTAND:-}" != "1" ]; then
  echo "Refusing to install system packages without I_UNDERSTAND=1." >&2
  echo "This installs Apache, MySQL, PHP 8.5, Phalcon 5.23, the Angular map and Photon." >&2
  echo "Photon indexes need about 15 GB under /var. Prefer 8 GB+ RAM for the DE index." >&2
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

export DEBIAN_FRONTEND=noninteractive

mem_mb="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
disk_gb="$(df -BG --output=avail /var | tail -1 | tr -dc '0-9')"
echo "RAM ${mem_mb} MB, free on /var: ${disk_gb} GB"
# Indexes land at ~15 GB. Peak during download+extract is a bit higher; 20 GB free is enough.
if [ "$disk_gb" -lt 20 ] && [ "$SKIP_PHOTON" != "1" ]; then
  echo "Need 20 GB free on /var for the Photon indexes (~15 GB installed; have ${disk_gb} GB)." >&2
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
  ca-certificates \
  bzip2 \
  rsync \
  unzip

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
    npx ng build --configuration=production
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
  umask 077
  cat > /etc/landkarte/db.env <<EOF
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
EOF
  chmod 600 /etc/landkarte/db.env
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

a2enmod rewrite >/dev/null
sed \
  -e "s|__API_HOST__|${API_HOST}|g" \
  -e "s|__APP_DIR__|${APP_DIR}|g" \
  "${SCRIPT_DIR}/apache-api.conf" > /etc/apache2/sites-available/landkarte-api.conf
sed \
  -e "s|__MAP_HOST__|${MAP_HOST}|g" \
  -e "s|__MAP_DIR__|${MAP_DIR}|g" \
  "${SCRIPT_DIR}/apache-map.conf" > /etc/apache2/sites-available/landkarte-map.conf
a2dissite 000-default >/dev/null || true
a2ensite landkarte-api.conf landkarte-map.conf >/dev/null
apachectl configtest
systemctl reload apache2

install -d -o www-data -g www-data /var/log/landkarte
sed "s|/var/www/api.landkarte.premium-cola.de|${APP_DIR}|g" \
  "${APP_DIR}/docs/cron/landkarte.cron" > /etc/cron.d/landkarte
chmod 644 /etc/cron.d/landkarte

if [ "$SKIP_PHOTON" != "1" ]; then
  echo "Installing Photon 0.5 (DE, AT, CH)"
  id photon >/dev/null 2>&1 || useradd --system --home-dir /var/lib/photon --shell /usr/sbin/nologin photon
  install -d -o photon -g photon /opt/photon /var/lib/photon /etc/photon
  if [ ! -f /opt/photon/photon-0.5.0.jar ]; then
    curl -fL --retry 5 --retry-delay 5 \
      -o /opt/photon/photon-0.5.0.jar \
      https://github.com/komoot/photon/releases/download/0.5.0/photon-0.5.0.jar
  fi
  install -m 755 "${SCRIPT_DIR}/photon/run.sh" /opt/photon/run.sh
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
  echo "Waiting for Photon DE on 127.0.0.1:2322 (index load can take several minutes)"
  ready=0
  for _ in $(seq 1 60); do
    if curl -sf -o /dev/null --max-time 2 "http://127.0.0.1:2322/api?limit=1&lang=de&q=Berlin"; then
      ready=1
      break
    fi
    sleep 5
  done
  if [ "$ready" != "1" ]; then
    echo "Photon DE is not answering yet. Check: journalctl -u photon@de -e" >&2
  else
    echo "Photon DE is answering."
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
curl -sS -H "Host: ${API_HOST}" \
  "http://127.0.0.1/item/list?types[]=laeden&countries[]=DE&products[]=cola" || true
echo
echo
echo "Next:"
echo "  1. Put Collmex user, password and customer_id into ${APP_DIR}/app/config/config.php"
echo "  2. Once DNS points here: ENABLE_TLS=1 TLS_EMAIL=... I_UNDERSTAND=1 bash $0"
echo "  3. Rebuild the map later: I_UNDERSTAND=1 SKIP_PHOTON=1 bash $0"
echo "Database password: /etc/landkarte/db.env"
echo "Frontend source: ${FRONTEND_SRC} → ${MAP_DIR}"
echo "Geocoding: php ${APP_DIR}/app/cli.php update geo   (cron does this hourly)"
