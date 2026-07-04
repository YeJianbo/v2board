FROM php:8.1-fpm-alpine

# Install dependencies
RUN apk add --no-cache \
    curl \
    git \
    libpng-dev \
    libzip-dev \
    oniguruma-dev \
    libsodium-dev \
    zip \
    unzip \
    mariadb-client

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd sodium

# Install Redis extension
RUN apk add --no-cache pcre-dev $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del pcre-dev $PHPIZE_DEPS

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
