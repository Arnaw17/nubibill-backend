#!/bin/sh
set -e

# Run Laravel optimizations
if [ -n "$APP_KEY" ]; then
    echo "Caching Laravel configuration and routes..."
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
fi

# Ensure storage link exists
php artisan storage:link || true

# Run database migrations if DB_HOST is configured
if [ -n "$DB_HOST" ]; then
    echo "Running database migrations..."
    php artisan migrate --force --isolated || true
fi

exec "$@"
