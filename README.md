# nexolu-spa-api

API de agenda para spas de uñas, barberías y estética. Producto propio dentro
del ecosistema Nexolú — **no** un módulo del POS.

## Por qué existe aparte

`nexolu-pos-api` ya sirve tienda, restaurante y mesas. Meterle la vertical de
agenda profunda lo convertiría en un producto que hace de todo y nada bien.
Este repo toma del POS la **plomería** (tenancy, auth, despacho de capacidades
de IA, capa de mensajería) y construye el dominio de agenda desde cero.

De `blue-souls-app` — el spa de uñas en producción — no se porta código. Sirve
como inventario de lo que el negocio necesita, nada más.

## Stack

Laravel 13 · PHP 8.3+ · MySQL · Sanctum · spatie/laravel-permission

Base de datos propia. No comparte esquema con el POS ni con el monolito legacy.

## Servicios Core que consume

| Servicio | Para qué |
|---|---|
| `nexolu-ia-core` | Agente conversacional y agendamiento por WhatsApp |
| `nexolu-comms-api` | WhatsApp y correo (único canal, sin driver alterno) |
| `nexolu-payments-core` | Cobro de la suscripción |

## Arrancar en local

Necesitas PHP 8.3+ con `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo` y
`zip`; Composer; y MySQL 8.

```bash
composer setup
php artisan migrate:fresh --seed
php artisan serve --port=8100
```

`composer setup` instala dependencias, copia el `.env`, genera la llave, corre
migraciones y sincroniza permisos. El seeder crea un negocio de ejemplo:
`demo@nexolu.test` / `password123`.

**Puertos.** El ecosistema Nexolú ya usa 8000–8003 (`pos-api`, `ia-core`,
`comms-api`, `payments-core`), 8010/8020 en producción, y 5173/5174 para los
otros frontends. Este producto usa **8100** para el API, **5273** para el front
y **3307** para MySQL, fuera de todos esos rangos para poder correr en paralelo
con el resto.

```
DB_PORT=3307
APP_URL=http://localhost:8100
FRONTEND_URL=http://localhost:5273
```

## Pruebas

```bash
php artisan test
```

Necesitan una base `nexolu_spa_testing` en el mismo MySQL (ver `phpunit.xml`).

`ConcurrentBookingTest` es la prueba que justifica el diseño: abre dos
conexiones y hace que peleen por el mismo hueco. Usa `DatabaseMigrations` y no
`RefreshDatabase` a propósito — el envoltorio transaccional dejaría los datos
sin commitear y la segunda conexión no los vería.

## Las dos decisiones de diseño que importan

### La disponibilidad se calcula, no se almacena

`AvailabilityService` la deriva de `horario recurrente − excepciones − ocupación`
sobre el rango consultado. No hay tabla de slots ni cron que la mantenga.

Blue Souls materializaba una fila por bloque de 120 minutos, por empleada, por
día. Eso congela la granularidad, llena la base de filas muertas y no sabe
representar un servicio de 20 minutos ni uno de tres horas.

### El anti-solape lo impone la base, no la aplicación

`resource_occupancy` tiene un índice único `(resource_id, slot_start)`.
`BookingService` escribe esas filas dentro de la misma transacción que la cita;
si otra transacción ya reclamó cualquiera de esas unidades, MySQL rechaza la
escritura y la cita entera se deshace.

Un chequeo previo tipo «¿está libre?» siempre deja una ventana entre la lectura
y la escritura. Con reserva online abierta y un agente de WhatsApp agendando en
paralelo, esa ventana se materializa. Es la traducción a MySQL de lo que en
Postgres sería un `EXCLUDE USING gist`.

## Modelo de datos

```
businesses ─┬─ users ─── resources ─┬─ resource_schedules
            │                       └─ schedule_exceptions
            ├─ services ─┬─ service_resource
            │            └─ service_resource_requirements
            ├─ clients ─── client_penalties
            └─ appointments ─── appointment_items ─── resource_occupancy
```

**`resources`** es cualquier cosa que una cita consume en exclusiva: la
profesional, la silla, la cabina, el equipo. Modelarlo como entidad propia —y no
como «usuario empleado»— es lo que permite que un servicio exija persona *y*
cabina a la vez.

## Multi-tenant

`business_id` en todo, vía `App\Traits\BelongsToBusiness`. Nunca viaja en el
request: se resuelve del usuario autenticado, y en creación se sobrescribe
siempre con el suyo, cerrando la inyección de tenant.

Fuera de contexto autenticado (comandos, jobs, tests) el scope no aplica — hay
que asignar `business_id` a mano o usar `withoutGlobalScope('business')`.

## Configuración vs. reglas de negocio

`config/spa.php` tiene **defaults de plataforma**, no políticas. Cada negocio
sobrescribe las suyas en `businesses.scheduling_settings`, y se leen siempre por
`Business::schedulingSetting()`.

Ningún servicio debe llamar `config('spa.defaults.*')` directo: eso volvería a
convertir una política de negocio en una constante de aplicación.

## Despliegue

Corre en el droplet legacy, junto a `pos-saas`. Ver [`deploy/README.md`](deploy/README.md)
para el runbook y las tres decisiones que no son obvias (red de host, Docker en
vez de PHP nativo, y por qué el frontend nunca se compila en el servidor).

## Estado

Fases 00–03 del blueprint. Esquema, modelos, motor de disponibilidad y reservas
funcionando, con 28 pruebas que los cubren. Los controladores HTTP responden
`501` hasta la fase que les toca — ver `routes/api.php`.
