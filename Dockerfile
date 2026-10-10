# ==========================================
# Stage 1: Build Frontend Assets with Node
# ==========================================
FROM node:22-alpine AS frontend
WORKDIR /app

COPY package*.json ./
RUN npm install --no-audit --no-fund

COPY vite.config.js ./
COPY resources/ ./resources/
COPY public/ ./public/

RUN npm run build

# ==========================================
# Stage 2: Install Composer Dependencies
# ==========================================
FROM composer:2 AS composer_build
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ==========================================
# Stage 3: Production Runtime Image (Instant Alpine)
# ==========================================
FROM alpine:3.20

# Install precompiled Nginx, Supervisor, PHP 8.3 & extensions (0% compilation, 100% instant binary packages)
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    bash \
    php83 \
    php83-fpm \
    php83-opcache \
    php83-pdo_mysql \
    php83-mbstring \
    php83-exif \
    php83-pcntl \
    php83-bcmath \
    php83-gd \
    php83-zip \
    php83-intl \
    php83-sockets \
    php83-pecl-redis \
    php83-curl \
    php83-openssl \
    php83-session \
    php83-tokenizer \
    php83-xml \
    php83-dom \
    php83-fileinfo \
    php83-phar \
    php83-simplexml \
    php83-xmlwriter \
    php83-iconv \
    php83-ctype \
    && ln -sf /usr/bin/php83 /usr/bin/php \
    && ln -sf /usr/sbin/php-fpm83 /usr/sbin/php-fpm \
    && mkdir -p /var/www/html /run/nginx /run/php /var/log/nginx

# Ensure www-data user exists
RUN if ! id -u www-data >/dev/null 2>&1; then \
        addgroup -g 82 -S www-data 2>/dev/null || true; \
        adduser -u 82 -D -S -G www-data www-data 2>/dev/null || true; \
    fi

# Copy configuration files
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/php/php.ini /etc/php83/conf.d/99_custom.ini
COPY docker/php/opcache.ini /etc/php83/conf.d/00_opcache.ini
COPY docker/php/www.conf /etc/php83/php-fpm.d/www.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Copy application files and build artifacts
COPY . /var/www/html
COPY --from=composer_build /app/vendor /var/www/html/vendor
COPY --from=frontend /app/public/build /var/www/html/public/build

# Set correct permissions
RUN mkdir -p /var/www/html/storage/framework/cache/data \
             /var/www/html/storage/framework/sessions \
             /var/www/html/storage/framework/views \
             /var/www/html/storage/app/public \
             /var/www/html/storage/logs \
             /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
