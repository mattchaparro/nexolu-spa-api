# Despliegue de nexolu-spa-api

Corre en el **droplet legacy `nexolu`** (`134.122.116.201`), el mismo que
sirve `pos.nexolu.co`. No en los droplets nuevos que administra `nexolu-infra`.

## Por qué ahí

Cuando termine la migración del POS, ese droplet queda sin tráfico productivo
y pasa a ser el del Spa. Ponerlo ahí desde el principio evita pagar un
servidor extra durante la transición.

## Con qué convive

| Qué | Cómo | Puerto |
|---|---|---|
| `pos-saas` → `pos.nexolu.co` | Nativo: nginx del host + php8.2-fpm + **MySQL nativo** | 80/443 |
| `nexolu-admin` → `api-admin.nexolu.co` | Contenedor Docker suelto | `127.0.0.1:8001` |
| `nexolu-admin-front` → `admin.nexolu.co` | Estático en `/var/www/` | — |
| **`nexolu-spa-api`** → `spa-backend.nexolu.co` | Contenedor Docker, red de host | `127.0.0.1:8030` |

## Las tres decisiones que no son obvias

### `--network host`, no bridge

El MySQL del droplet es nativo y escucha en `127.0.0.1`. Con red bridge habría
que abrirle el `bind-address` al rango de Docker — o sea, tocarle la
configuración a la base que sirve la producción real de `pos-saas`. Con red de
host el contenedor llega directo y no se toca nada.

El costo: el nginx interno del contenedor escucha en **8030**, no en 80. Con
red de host el 80 ya es del nginx del sistema y el 8001 de `nexolu-admin`.

### Docker, no PHP nativo

El droplet tiene `php8.2-fpm` y esta app necesita 8.4. Instalar una segunda
versión nativa junto a la que sirve producción es más riesgo que un contenedor
—y Docker ya corre ahí para `nexolu-admin`.

### El frontend nunca se compila en el servidor

1 core. `pos-saas/scripts/pos_deploy.sh` documenta que compilar ahí degrada las
requests en vivo entre 100 y 800 veces. `nexolu-spa-front` se compila en la
máquina del desarrollador y se sube ya construido.

Por lo mismo, `deploy.sh` corre el `docker build` con `nice`/`ionice`.

## Primera instalación

```bash
ssh root@134.122.116.201
```

**1. Clonar**

```bash
mkdir -p /opt && cd /opt
git clone https://github.com/mattchaparro/nexolu-spa-api.git
cd nexolu-spa-api
```

**2. Base de datos** — usuario propio, no el de `pos-saas`: si algo del Spa se
descontrola, no puede tocar la base del monolito.

```sql
CREATE DATABASE nexolu_spa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nexolu_spa'@'localhost' IDENTIFIED BY '<clave>';
GRANT ALL PRIVILEGES ON nexolu_spa.* TO 'nexolu_spa'@'localhost';
FLUSH PRIVILEGES;
```

**3. `.env`**

```bash
cp .env.example .env
```

Ajustar: `APP_ENV=production`, `APP_DEBUG=false`,
`APP_URL=https://spa-backend.nexolu.co`, `DB_DATABASE=nexolu_spa`,
`DB_USERNAME=nexolu_spa`, `DB_PASSWORD=<clave>`, `DB_HOST=127.0.0.1`,
`FRONTEND_URL=https://spa.nexolu.co`.

Generar la llave: `docker run --rm -v "$(pwd):/app" -w /app php:8.4-cli php artisan key:generate`

**4. nginx y TLS**

```bash
cp deploy/nginx/spa-backend.nexolu.co.conf /etc/nginx/sites-available/
ln -s /etc/nginx/sites-available/spa-backend.nexolu.co.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
certbot --nginx -d spa-backend.nexolu.co
```

Requiere que el A record ya resuelva. **El DNS de `nexolu.co` no está en la
cuenta de DigitalOcean** y dónde vive no está documentado — averiguarlo antes
de este paso.

**5. Desplegar**

```bash
bash deploy.sh
```

## Deploys siguientes

```bash
ssh root@134.122.116.201 'cd /opt/nexolu-spa-api && bash deploy.sh'
```

## Verificación

```bash
curl -s https://spa-backend.nexolu.co/up          # 200
curl -s -o /dev/null -w '%{http_code}' https://pos.nexolu.co   # el monolito sigue sano
ssh root@134.122.116.201 'uptime && docker ps'    # load average bajo, ambos contenedores arriba
```

## Cola y scheduler

Todavía **no se despliegan**. El POS corre tres contenedores (web, queue,
scheduler); acá arrancamos sólo con `web` mientras `pos-saas` siga sirviendo
tráfico real en el único core. Los recordatorios llegan en la fase 05, cuando
`nexolu-comms-api` esté probado.

## Pendiente

- **Sin backups automatizados**, igual que el resto del ecosistema
  (`nexolu-infra/README.md`). El Spa nace con el mismo hueco.
- **`nexolu-admin` no conoce este servicio**: falta agregarlo a
  `_DEPLOY_SERVICES`, `SERVICE_ENV_FILES`, `SERVICE_REPOS` y
  `SERVICE_DROPLET_ROLE` para poder desplegarlo desde el panel. Mientras tanto,
  por SSH.
