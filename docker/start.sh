#!/bin/sh
set -e

cd /var/www/html

# Run master migrations and seed admin on startup
php artisan migrate --path=database/migrations/master --force 2>&1 || true
php artisan db:seed --class=SaasAdminSeeder --force 2>&1 || true

# Start supervisor (nginx + php-fpm)
exec /usr/bin/supervisord -c /etc/supervisord.conf
