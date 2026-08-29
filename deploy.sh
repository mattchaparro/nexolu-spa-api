#!/usr/bin/env bash
#
# Deploy de nexolu-spa-api EN el droplet legacy `nexolu`, donde ya viven
# pos-saas y el panel nexolu-admin.
#
#   ssh root@134.122.116.201 'cd /opt/nexolu-spa-api && bash deploy.sh'
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
docker stop "$CONTAINER" 2>/dev/null || true
docker rm "$CONTAINER" 2>/dev/null || true
docker run -d \
    --name "$CONTAINER" \
    --restart unless-stopped \
    --network host \
    -v "$(pwd)/storage:/var/www/html/storage" \
    --env-file .env \
    "$IMAGE"

echo "[deploy] 4/5 Migraciones"
# Explicitas y una sola vez, nunca en el entrypoint: un reinicio del
# contenedor no debe poder alterar el esquema.
docker exec -u www-data "$CONTAINER" php artisan migrate --force

echo "[deploy] 5/5 Sincronizando permisos (idempotente, no borra nada)"
docker exec -u www-data "$CONTAINER" php artisan permissions:sync

echo "[deploy] Listo. Verificar:"
echo "         curl -s https://spa-backend.nexolu.co/up"
echo "         curl -s https://pos.nexolu.co   <- que el monolito siga sano"
