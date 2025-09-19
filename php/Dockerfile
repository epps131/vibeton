FROM php:8.2.13-fpm

COPY config/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY config/php.ini /usr/local/etc/php

RUN apt-get update && apt-get install -y \
    libssl-dev \
    zlib1g-dev \
    libicu-dev \
    g++ \
    libzip-dev \
    unzip \
    libpng-dev \
    wget \
    curl \
    git \
    mariadb-client \
    librabbitmq-dev \
    libpq-dev \
    && apt-get clean

RUN pecl install apcu xdebug-3.2.2 redis-6.0.2 amqp-2.1.1
RUN docker-php-ext-enable apcu redis amqp xdebug

RUN docker-php-ext-install -j$(nproc)   zip \
    intl \
    pdo_mysql \
    opcache \
    bcmath \
    exif \
    gd \
    sockets \
    pcntl \
    pdo_pgsql

RUN curl https://getcomposer.org/installer | php \
    && mv composer.phar /usr/bin/composer

WORKDIR /app
