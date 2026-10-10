# ==========================================
# Stage 1: Build Frontend Assets with Node
# ==========================================
FROM node:22-alpine AS frontend
WORKDIR /app

COPY package*.json ./
RUN npm ci || npm install

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
# Stage 3: Production Runtime Image
# ==========================================
FROM php:8.3-fpm-alpine

# Install system dependencies & build tools
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    bash \
    freetype \
    libpng \
    libjpeg-turbo \
    libzip \
    icu-libs \
    oniguruma \
    $PHPIZE_DEPS \
    freetype-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    libxml2-dev \
    linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del --no-cache $PHPIZE_DEPS freetype-dev libpng-dev libjpeg-turbo-dev libzip-dev icu-dev oniguruma-dev libxml2-dev linux-headers \
    && rm -rf /tmp/pear /var/cache/apk/*

# Copy configuration files
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Copy application files
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
