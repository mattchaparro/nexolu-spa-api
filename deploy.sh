#!/usr/bin/env bash
#
# Deploy de nexolu-spa-api EN el droplet legacy `nexolu`, donde ya viven
# pos-saas y el panel nexolu-admin.
#
#   ssh root@134.122.116.201 'cd /opt/nexolu/nexolu-spa-api && bash deploy.sh'
#
#   bash deploy.sh            pull + build + recrear + migrar
#   bash deploy.sh recrear    SOLO recrear el contenedor, sin build ni pull
#
# `recrear` existe para cuando cambia el .env. El contenedor se levanta con
# --env-file, y esas variables quedan fijadas cuando se CREA, no cuando
# arranca: `docker restart` seguiria con las viejas sin decir nada. El panel
# nexolu-admin llama justo a este subcomando despues de editar variables
# (ver app/infra/env_files.py:SERVICE_ENV_FILES), en vez de repetir alla los
# flags de `docker run` -- duplicarlos es garantizar que un dia queden
# distintos de los de aca.
#
# Sigue el patron de nexolu-admin (contenedor suelto, sin compose) y no el
# de nexolu-pos-api: ese asume el docker-compose.yml de nexolu-infra, que
# solo existe en los droplets nuevos. Este servidor no lo tiene.
#
# TRES COSAS QUE NO SE DEBEN CAMBIAR SIN PENSARLO:
#
# 1. --network host. El MySQL de este droplet es nativo y escucha en
#    127.0.0.1. Con red bridge habria que abrirle el bind-address al rango
#    de Docker, o sea tocarle la configuracion a la base que sirve la
#    produccion real de pos-saas. Con red de host el contenedor llega
#    directo y no se toca nada. El costo es que nginx interno escucha en
#    8030 (ver docker/nginx.conf): con red de host el 80 ya es del nginx
#    del sistema y el 8001 de nexolu-admin.
#
# 2. La imagen se construye aca, pero el frontend NUNCA. Este droplet tiene
#    1 core, y pos-saas/scripts/pos_deploy.sh documenta que compilar aca
#    degrada las requests en vivo entre 100 y 800 veces. nexolu-spa-front
#    se compila en la maquina del desarrollador y se sube ya construido.
#
# 3. nice/ionice en el build, por lo mismo: mientras pos-saas siga sirviendo
#    trafico real, un deploy no puede monopolizar el unico core.
set -euo pipefail
cd "$(dirname "$0")"

IMAGE="nexolu-spa-api:latest"
CONTAINER="nexolu-spa-api"
WORKER="nexolu-spa-worker"
MODO="${1:-completo}"

# Levantar el contenedor vive en una sola funcion porque hay DOS caminos que
# lo hacen -- el deploy completo y `recrear` -- y si los flags se separan, el
# reinicio por cambio de .env deja corriendo algo distinto de lo que deja un
# deploy.
levantar() {
    docker stop "$CONTAINER" 2>/dev/null || true
    docker rm "$CONTAINER" 2>/dev/null || true
    docker run -d \
        --name "$CONTAINER" \
        --restart unless-stopped \
        --network host \
        -v "$(pwd)/storage:/var/www/html/storage" \
        --env-file .env \
        "$IMAGE"

    levantar_worker
}

# El worker vive en SU PROPIO contenedor, con la misma imagen y otro comando.
#
# Se recrea junto al web en cada deploy, a proposito: un worker que sigue
# corriendo la imagen anterior procesa jobs con el codigo viejo, y el sintoma
# es de los peores -- la web se comporta como la version nueva y los
# recordatorios como la vieja, sin nada en los logs que lo explique.
#
# El tope de medio core NO es tacanieria. Este droplet tiene UNO solo, y lo
# comparte con el MySQL y el php-fpm que sirven pos.nexolu.co en produccion.
# Una rafaga de difusiones no puede quedarse con la maquina: bajo el tope el
# worker tarda mas, y eso no lo ve nadie; sin el, el monolito atiende mas
# lento, y eso si lo ve un cliente.
#
# `--max-time=3600` lo recicla cada hora: Laravel arranca el framework una
# vez y lo mantiene vivo, asi que cualquier fuga de memoria se acumula. Morir
# y volver es mas barato que perseguirla.
levantar_worker() {
    docker stop "$WORKER" 2>/dev/null || true
    docker rm "$WORKER" 2>/dev/null || true
    docker run -d \
        --name "$WORKER" \
        --restart unless-stopped \
        --network host \
        -v "$(pwd)/storage:/var/www/html/storage" \
        --env-file .env \
        --cpus=0.5 \
        --memory=256m \
        --user www-data \
        "$IMAGE" \
        php artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600
}

if [ "$MODO" = "recrear" ]; then
    echo "[deploy] Recreando el contenedor para que tome el .env actual"
    levantar
    echo "[deploy] Listo. Sin build ni migraciones: solo se releyeron las variables."
    exit 0
fi

NICE_CMD=""
if command -v nice >/dev/null 2>&1; then
    if command -v ionice >/dev/null 2>&1; then
        NICE_CMD="ionice -c3 nice -n 19"
    else
        NICE_CMD="nice -n 19"
    fi
fi

echo "[deploy] 1/5 git pull"
git pull origin main

echo "[deploy] 2/5 docker build (prioridad baja: pos-saas sigue sirviendo)"
$NICE_CMD docker build -t "$IMAGE" .

echo "[deploy] 3/5 Reiniciando contenedor"
levantar

echo "[deploy] 4/5 Migraciones"
# Explicitas y una sola vez, nunca en el entrypoint: un reinicio del
# contenedor no debe poder alterar el esquema.
docker exec -u www-data "$CONTAINER" php artisan migrate --force

echo "[deploy] 5/5 Sincronizando permisos (idempotente, no borra nada)"
docker exec -u www-data "$CONTAINER" php artisan permissions:sync

echo "[deploy] 6/6 Contenedores"
docker ps --filter "name=nexolu-spa" --format "   {{.Names}}: {{.Status}}"

echo "[deploy] Listo. Verificar:"
echo "         curl -s https://agenda-backend.nexolu.co/up"
echo "         curl -s https://pos.nexolu.co   <- que el monolito siga sano"
