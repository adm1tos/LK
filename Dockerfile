FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y \
        git \
        unzip \
        mariadb-client \
        openssh-client \
        netcat-openbsd \
        libzip-dev \
        libonig-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql curl mbstring zip sockets \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

COPY docker/apache-lk.conf /etc/apache2/conf-available/lk.conf

RUN a2enconf lk

COPY . .

RUN mkdir -p /var/www/html/storage/keys /var/www/html/storage/scripts \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 750 /var/www/html/storage