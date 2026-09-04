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

# `public/storage` -> `storage/app/public`.
#
# Nadie mas lo hace: no esta en el Dockerfile, ni en deploy.sh, ni en
# `composer setup`. Y el sintoma de que falte es SILENCIOSO en el peor
# sentido: la subida funciona, el archivo queda guardado, y lo unico que
# falla es la URL -- todas las imagenes del producto responden 404 y nadie
# se entera hasta que alguien abre una ficha.
#
# Va en el arranque y no en el build porque `storage/` es un volumen: el
# enlace tiene que existir contra el que este montado hoy. `--force` lo hace
# idempotente, que es lo que permite reiniciar el contenedor sin pensarlo.
#
# Como root a proposito: `public/` es de root en la imagen y www-data no
# puede crear ahi. El enlace en si lo lee nginx, que no le importa el dueno.
php artisan storage:link --force

# Cachear configuracion, rutas y vistas es deterministico y no toca la base.
su -s /bin/sh www-data -c "php artisan config:cache"
su -s /bin/sh www-data -c "php artisan route:cache"

exec "$@"
