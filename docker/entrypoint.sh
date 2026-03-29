#!/bin/sh
set -e

# Generate app key if not set
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

# Cache config & routes for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations
php artisan migrate --force

# Run seeders if needed (first-time setup)
if [ "$RUN_SEEDERS" = "true" ]; then
    php artisan db:seed --force
fi

exec "$@"
