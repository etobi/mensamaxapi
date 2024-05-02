FROM composer:latest as composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-scripts --no-dev --no-autoloader && \
    composer clear-cache
COPY . .
RUN composer dump-autoload --no-scripts --no-dev --optimize

# Depending on the composer you use, you may be required to use a different php version.
FROM php:8.2-apache
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

# RUN apt-get update && apt-get install -y \
#    zip \
# && rm -rf /var/lib/apt/lists/*

# RUN echo "ServerName app.local" >> /etc/apache2/apache2.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN a2enmod rewrite headers actions

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# RUN docker-php-ext-install \
# zip

COPY --from=composer /app /var/www/html

ARG uid
RUN useradd -G www-data,root -u $uid -d /home/appuser appuser
RUN mkdir -p /home/appuser/.composer && \
    chown -R appuser:appuser /home/appuser

USER appuser
RUN echo "export PATH=$PATH:/var/www/html/vendor/bin" >> /home/appuser/.bashrc
