FROM php:8.2-cli-alpine

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

ADD . /app

RUN composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader

RUN echo 'memory_limit = 2048M' >> /usr/local/etc/php/conf.d/docker-php-memlimit.ini;

CMD ["php", "bin/console", "server:run", "--ip=0.0.0.0", "--port=8080"]
