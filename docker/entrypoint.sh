#!/bin/sh
set -e

# El chown del Dockerfile solo cubre lo que existe al construir la imagen.
# `storage/` vive en un volumen que persiste entre builds, y cualquier
# archivo nuevo dentro hereda el usuario de quien lo creo, no el del
# directorio padre. Si eso pasa siendo root -- por ejemplo un `docker exec`
# de artisan sin `-u www-data` durante un deploy -- despues php-fpm, que
# corre como www-data, no puede escribir su propio log: una excepcion queda
# sin registrar y la request explota en un 500 sin rastro.
#
# Reafirmar el dueno en cada arranque es robusto contra cualquier causa
# futura del mismo problema, no solo la de hoy.
chown -R www-data:www-data /var/www/html/storage

# Aca NO se corre `php artisan migrate`.
#
# A diferencia del POS -- que no migra porque su esquema viene de un
# schema.sql heredado -- esta app si tiene migraciones propias. La razon es
# otra: migrar en cada arranque de contenedor significa que un simple
# reinicio (OOM, `docker restart`, reboot del droplet) puede alterar el
# esquema en un momento que nadie eligio. Las migraciones las corre
# deploy.sh, una vez, de forma explicita y observable.

# Cachear configuracion, rutas y vistas es deterministico y no toca la base.
su -s /bin/sh www-data -c "php artisan config:cache"
su -s /bin/sh www-data -c "php artisan route:cache"

exec "$@"
