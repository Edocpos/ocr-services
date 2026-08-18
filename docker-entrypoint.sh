#!/bin/sh
set -e

cd /app

mkdir -p storage/framework/views \
		 storage/framework/cache \
		 storage/framework/sessions \
		 storage/framework/testing \
		 storage/logs

php artisan optimize:clear || true
chmod -R 775 storage bootstrap/cache || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec php artisan serve --host=0.0.0.0 --port=8000
