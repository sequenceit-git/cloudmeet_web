#!/bin/sh
set -e

# Ensure storage and bootstrap/cache directories exist
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/app/public \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

# Fix permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create storage symlink if not already linked
if [ ! -L /var/www/html/public/storage ]; then
    php artisan storage:link --force || true
fi

# Run database migrations if RUN_MIGRATIONS is set to true
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "Running database migrations..."
    php artisan migrate --force || true
fi

# Cache configuration, routes, and views if in production
if [ "$APP_ENV" = "production" ]; then
    echo "Optimizing Laravel for production..."
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
    php artisan event:cache || true
fi

# Execute CMD passed to docker container
exec "$@"
