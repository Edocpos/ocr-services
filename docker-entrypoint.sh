#!/bin/sh
set -e

cd /app

php artisan optimize:clear || true
chmod -R 775 storage bootstrap/cache || true

exec php artisan serve --host=0.0.0.0 --port=8000
