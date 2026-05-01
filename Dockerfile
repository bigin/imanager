FROM php:8.3-cli-alpine

RUN apk add --no-cache \
        bash \
        git \
        make \
        sqlite \
        sqlite-dev \
        oniguruma-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libxml2-dev \
        icu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        mbstring \
        gd \
        dom \
        zip \
        opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD ["sh"]
