FROM composer:2 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-dev --no-scripts --no-autoloader --no-interaction
COPY bin ./bin
COPY src ./src
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM php:8.4-cli-alpine
ENV TZ=Europe/Berlin \
    DATA_DIR=/data \
    PUBLISH_INTERVAL=3600
RUN apk add --no-cache tzdata \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'date.timezone=Europe/Berlin\nmemory_limit=128M\n' > "$PHP_INI_DIR/conf.d/mensamax.ini" \
    && addgroup -S app && adduser -S -G app app \
    && mkdir -p /data && chown app:app /data
WORKDIR /app
COPY --from=composer /app /app
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh /app/bin/mensamax
USER app
VOLUME ["/data"]
ENTRYPOINT ["/entrypoint.sh"]
CMD ["loop"]
