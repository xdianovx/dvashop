FROM php:8.4-fpm-bookworm

ARG WWWUSER=1000
ARG WWWGROUP=1000

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        curl \
        git \
        unzip \
        default-mysql-client \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/local/php/php.ini /usr/local/etc/php/conf.d/99-dvashop.ini

RUN groupadd --force -g ${WWWGROUP} app \
    && useradd -ms /bin/bash --no-user-group -g ${WWWGROUP} -u ${WWWUSER} app \
    && sed -i 's/user = www-data/user = app/g' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/group = www-data/group = app/g' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/listen.owner = www-data/listen.owner = app/g' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/listen.group = www-data/listen.group = app/g' /usr/local/etc/php-fpm.d/www.conf

USER app

CMD ["php-fpm"]
