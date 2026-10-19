#!/bin/sh
set -e

cd /var/www/html

a2enmod rewrite >/dev/null

if [ ! -f app/config/config.php ]; then
  cp app/config/config.docker.php app/config/config.php
  echo "Created app/config/config.php from config.docker.php"
fi
# Bind-mount: container root would leave config.php unwritable for the host IDE
if [ -f app/config/config.php ]; then
  chown "${HOST_UID:-1000}:${HOST_GID:-1000}" app/config/config.php 2>/dev/null || true
fi

if [ ! -d vendor ]; then
  composer install --no-interaction
fi

mkdir -p app/cache
chmod -R a+rwX app/cache 2>/dev/null || true

php app/migrate.php || echo "Schema migration skipped (database not ready)"

exec "$@"
