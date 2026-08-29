# Imagen de nexolu-spa-api.
#
# Corre en el droplet legacy `nexolu`, el mismo que sirve pos-saas. Ese
# servidor tiene php8.2-fpm nativo y esta app necesita 8.4: el contenedor
# resuelve eso sin tocar el PHP del que depende el monolito en produccion.
#
# Ver deploy/README.md para el runbook.

# ---- Etapa 1: dependencias de Composer ----
# No hay etapa de assets: este repo es una API pura, el frontend vive en
# nexolu-spa-front y se despliega como estatico aparte.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev

# ---- Etapa 2: runtime (PHP-FPM + nginx + supervisor en un contenedor) ----
FROM php:8.4-fpm-alpine

# libzip se instala ANTES del grupo virtual a proposito: asi `apk del
# .build-deps` (que se lleva libzip-dev) no arrastra el .so que la extension
# zip necesita en runtime.
#
# tzdata: sin esto `date` del sistema, y los logs de nginx y supervisor,
# quedan en UTC. Carbon no lo necesita (trae su propia tabla), pero el resto
# del SO si.
#
# Sin la extension redis a proposito: esta app usa QUEUE_CONNECTION=database
# y CACHE_STORE=database. El droplet legacy no tiene Redis, y agregarlo seria
# otro proceso compitiendo por su unico core.
RUN apk add --no-cache nginx supervisor libzip tzdata \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libzip-dev oniguruma-dev \
    && docker-php-ext-install pdo_mysql zip opcache \
    && apk del .build-deps

COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache-custom.ini

WORKDIR /var/www/html
COPY --from=vendor /app ./

RUN chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# 8030 y no 80: el contenedor corre con la red del host para poder llegar al
# MySQL nativo en 127.0.0.1:3306 sin abrir su bind-address al rango de
# Docker. Con red de host, el 80 ya es del nginx del sistema (pos-saas) y el
# 8001 de nexolu-admin.
EXPOSE 8030
ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
