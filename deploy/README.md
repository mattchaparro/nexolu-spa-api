# Despliegue de nexolu-spa-api

Corre en el **droplet legacy `nexolu`** (`134.122.116.201`), el mismo que
sirve `pos.nexolu.co`. No en los droplets nuevos que administra `nexolu-infra`.

> **El repo se llama `spa` pero el dominio es `agenda`.** No es un descuido:
> el producto ya atiende spas de uñas, barberías y centros de estética (ver
> `BusinessFeaturePresets::verticals()`), y `spa.nexolu.co` lo encerraba en
> una sola de las tres delante del cliente. El dominio es lo que ve la gente;
> el nombre del repo es interno y renombrarlo rompe clones, remotos, la
> deploy key y el mapeo del panel, así que se deja para cuando haya una razón
> más fuerte que la estética.

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
| **`nexolu-spa-api`** → `agenda-backend.nexolu.co` | Contenedor Docker, red de host | `127.0.0.1:8030` |

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

**1. Instalar la deploy key y clonar**

El repo se clona por SSH y no por HTTPS: el servidor tiene que poder hacer
`git pull` solo, sin que nadie escriba un token. La llave ya está registrada
en GitHub como deploy key **de solo lectura** (el droplet nunca escribe al
repo). La privada está en la máquina del dev, en
`~/.ssh/nexolu_spa_api_deploy` — se sube así:

```bash
# desde la máquina del dev
scp ~/.ssh/nexolu_spa_api_deploy root@134.122.116.201:/root/.ssh/nexolu_spa_api_deploy
ssh root@134.122.116.201 'chmod 600 /root/.ssh/nexolu_spa_api_deploy'
```

Y en el droplet, para que git la use solo con GitHub:

```bash
cat >> /root/.ssh/config <<'EOF'

Host github-spa-api
    HostName github.com
    User git
    IdentityFile /root/.ssh/nexolu_spa_api_deploy
    IdentitiesOnly yes
EOF
chmod 600 /root/.ssh/config
```

`IdentitiesOnly yes` no es adorno: sin eso, ssh ofrece todas las llaves que
tenga el agente y GitHub responde con la identidad de la primera que acepte
— que puede ser la deploy key de OTRO repo, y entonces el clone falla con un
"repository not found" que no dice nada.

```bash
mkdir -p /opt/nexolu && cd /opt/nexolu
git clone github-spa-api:mattchaparro/nexolu-spa-api.git
cd nexolu-spa-api
```

> La ruta es `/opt/nexolu/nexolu-spa-api`, igual que el resto del ecosistema
> y que lo que espera el panel admin (`SERVICE_REPOS`).

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
`APP_URL=https://agenda-backend.nexolu.co`, `DB_DATABASE=nexolu_spa`,
`DB_USERNAME=nexolu_spa`, `DB_PASSWORD=<clave>`, `DB_HOST=127.0.0.1`,
`FRONTEND_URL=https://agenda.nexolu.co`.

Generar la llave: `docker run --rm -v "$(pwd):/app" -w /app php:8.4-cli php artisan key:generate`

**4. nginx y TLS**

```bash
cp deploy/nginx/agenda-backend.nexolu.co.conf /etc/nginx/sites-available/
ln -s /etc/nginx/sites-available/agenda-backend.nexolu.co.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
certbot --nginx -d agenda-backend.nexolu.co
```

Requiere que el A record ya resuelva. **El DNS de `nexolu.co` vive en
Hostinger**, no en DigitalOcean (nameservers `nebula`/`aurora.dns-parking.com`,
verificado el 2026-08-30). Los registros se crean en el panel de Hostinger y
`doctl` no los ve:

| Tipo | Nombre | Valor |
|---|---|---|
| A | `agenda-backend` | `134.122.116.201` |
| A | `agenda` | `134.122.116.201` |

Confirmar que propagó antes de correr certbot — si no, la validación HTTP-01
falla y certbot deja el vhost a medio configurar:

```bash
nslookup agenda-backend.nexolu.co 8.8.8.8
```

**5. Desplegar**

```bash
bash deploy.sh
```

## Deploys siguientes

Desde el panel superadmin (`admin.nexolu.co` → Infraestructura → desplegar
`spa-api`), o por SSH:

```bash
ssh root@134.122.116.201 'cd /opt/nexolu/nexolu-spa-api && bash deploy.sh'
```

Si sólo cambiaron variables de entorno, no hace falta un deploy completo:

```bash
ssh root@134.122.116.201 'cd /opt/nexolu/nexolu-spa-api && bash deploy.sh recrear'
```

Es lo mismo que hace el panel después de editar el `.env`. **`docker restart`
no sirve**: el contenedor se levanta con `--env-file` y esas variables quedan
fijadas cuando se crea, no cuando arranca — reiniciarlo seguiría con las
viejas sin decir nada.

## Verificación

```bash
curl -s https://agenda-backend.nexolu.co/up          # 200
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
- **El panel admin ya conoce este servicio** (`_DEPLOY_SERVICES`,
  `SERVICE_ENV_FILES`, `SERVICE_REPOS`, `SERVICE_DROPLET_ROLE`,
  `SERVICE_DEPLOY_COMMANDS`), pero para que funcione hay que darle la llave
  SSH del droplet legacy: `ENVIRONMENTS__PROD__DROPLETS__SPA__SSH_KEY_PATH`
  apunta a `/ssh/prod_spa_deploy_key` **dentro del contenedor del panel**, y
  esa llave todavía no está montada. Hasta entonces, deploy por SSH.
- **`spa-front` no se despliega desde el panel** y no es un olvido: se
  compila en la máquina del dev (este droplet no puede correr Node) y se
  sube con `rsync`. El panel despliega tirando de git EN el servidor, que es
  justo lo que acá no se puede hacer.
