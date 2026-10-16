#!/bin/sh
set -eu
cd /var/www/html
: "${APP_KEY:?Set a stable Laravel APP_KEY in Render before deploying}"
: "${DB_HOST:?Set the Aiven database host}"
: "${DB_PASSWORD:?Set the Aiven database password}"
: "${MYSQL_ATTR_SSL_CA:?Set the path of the Aiven CA secret file}"
test -r "$MYSQL_ATTR_SSL_CA"
# Render mounts secret files with owner-only permissions. Copy the public CA
# certificate into app storage so Apache, the queue worker, and Reverb can read it.
install -o www-data -g www-data -m 0400 "$MYSQL_ATTR_SSL_CA" /var/www/html/storage/app/private/aiven-ca.pem
export MYSQL_ATTR_SSL_CA=/var/www/html/storage/app/private/aiven-ca.pem
PORT="${PORT:-10000}"
case "$PORT" in ''|*[!0-9]*) echo 'Invalid HTTP port' >&2; exit 1;; esac
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
php artisan config:cache
php artisan package:discover --ansi
php artisan migrate --force
php artisan route:cache
php artisan view:cache
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
