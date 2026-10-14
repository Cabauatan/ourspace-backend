FROM php:8.5-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates git unzip supervisor libicu-dev libzip-dev \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 pdo_mysql intl zip gd pcntl \
    && a2enmod rewrite proxy proxy_http proxy_wstunnel setenvif \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database/migrations ./database/migrations
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY artisan ./artisan
COPY render-apache.conf /etc/apache2/sites-available/000-default.conf
COPY render-supervisor.conf /etc/supervisor/conf.d/ourspace.conf
COPY render-php.ini /usr/local/etc/php/conf.d/ourspace.ini
COPY render-start.sh /usr/local/bin/ourspace-start
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && rm -f bootstrap/cache/*.php \
    && composer dump-autoload --no-dev --optimize --no-scripts \
    && chmod +x /usr/local/bin/ourspace-start \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 10000
CMD ["/usr/local/bin/ourspace-start"]
