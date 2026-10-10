#!/bin/sh

# Ensure storage and bootstrap/cache directories exist
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/app/public \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache \
         /run/nginx \
         /run/php \
         /var/log/nginx

# Fix permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /run/nginx /run/php /var/log/nginx 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Generate APP_KEY if not present
if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is empty. Generating new application encryption key..."
    php artisan key:generate --force || true
fi

# Create storage symlink if not already linked
if [ ! -L /var/www/html/public/storage ]; then
    php artisan storage:link --force 2>/dev/null || true
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
