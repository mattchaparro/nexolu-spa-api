# Migración de Luxury Nails: del spa legacy a Nexolú Agenda

Análisis hecho contra la base de datos REAL (`spa_app` en el droplet, solo
lectura) y el código de `blue-souls-app`. Cifras del 2026-09-07.

## Qué hay que traer, en números

| Qué | Filas | Va o no va |
|---|---|---|
| Clientas (`clients`) | **767** | ✅ El activo principal |
| Atenciones terminadas (`employee_services`, status 2) | **3.329** | ✅ Es el "cuántos servicios llevan" |
| Citas futuras (status 4 Agendado) | ~100 | ✅ Por el motor de reservas, no por SQL |
| Servicios activos | **44** en 6 categorías | ✅ |
| Empleadas activas (`users`) | 7 (una duplicada) | ✅ Como recursos |
| Turnos (`work_shifts`) | 34 | ✅ Como horarios semanales |
| Tarjetas de sellos (`loyalty_cards`) | **356** | ✅ Con decisión de producto (abajo) |
| Calificaciones (`service_ratings`) | 189 | ✅ Alimentan la página pública |
| Bajas de WhatsApp (`unsuscribe_contacts`) | 23 | ✅ Como `accepts_marketing = false` |
| Rejilla de slots (`time_slots`) | 4.847 | ❌ El motor nuevo la calcula; migrarla sería importar la limitación de bloques fijos de 120 min de la que venimos huyendo |
| Notificaciones enviadas, fallos de gamificación, logs | ~3.500 | ❌ Bitácoras de un sistema que se apaga |
| Gastos, cierres, nóminas, productos, ventas | ~700 | ⚠️ Decisión abierta (abajo) |

## Los tres hallazgos que cambian el plan

### 1. La metadata de ManyChat es más rica que la tabla

`clients` NO tiene columna de email. Pero `clients.metadata` guarda el
suscriptor completo de ManyChat: **email, `optin_whatsapp`, `whatsapp_phone`
con indicativo, Instagram, género, última interacción**. La migración buena
lee el JSON, no solo las columnas.

- `metadata.email` → `clients.email`
- `metadata.optin_whatsapp` + `unsuscribe_contacts` → `accepts_marketing`
- `external_id` (subscriber de ManyChat) → tabla de mapeo (abajo)

### 2. El historial vive en `employee_services`, no en `appointments`

`appointments` legacy no tiene ni hora (está en `time_slots`) ni estado (está
en `employee_services.status_id`) ni precio. La fila que importa es
`employee_services`: precio, precio final cobrado, comisión, medio de pago,
descuentos, `started_at`/`finished_at`.

Por eso "visitas de una clienta" = atenciones con `status_id = 2`
(FINALIZADO). Trampa documentada: la FK de `employee_services.client_id`
está declarada contra `users` aunque contiene ids de `clients` — mapear por
el valor, no confiar en la constraint.

### 3. Todo está en hora de Bogotá, no en UTC

`config/app.php` legacy tiene `America/Bogota` **hardcodeado**. El sistema
nuevo guarda UTC. Cada fecha-hora migrada se interpreta como `-05:00` y se
convierte. Equivocarse aquí corre TODO el historial cinco horas — el mismo
bug de `toISOString()` que ya nos mordió dos veces, ahora en masa.

## El mapeo, tabla por tabla

### Clientas → `clients`

| Legacy | Nuevo | Transformación |
|---|---|---|
| `first_name`/`last_name` (o `name`) | `name`, `last_name` | `name` legacy a veces es el completo |
| `cellphone` | `phone` | 10 dígitos sin indicativo → `ChannelPhone::normalize` (57…) |
| `metadata.email` | `email` | del JSON |
| `gender` | `gender` | |
| `metadata.optin_whatsapp` y bajas | `accepts_marketing` | baja o sin opt-in → `false` |
| `external_id` | tabla `legacy_map` | ver idempotencia |

Calidad real: 755/767 con teléfono limpio de 10 dígitos, 6 sin teléfono,
**4 duplicados** (misma línea, dos fichas → se fusionan, gana la de más
visitas y la otra aporta lo que falte), y un puñado con formatos raros que
se normalizan o quedan reportados.

### Servicios → `services` + `service_categories`

`service_types` (Manicure, Pedicure, Pestañas, Cejas, Peinados, Combos) →
categorías. 44 servicios con precio y duración directos (`duration` ya está
en minutos: 15–240). Sin buffers en legacy → nacen en 0 y el negocio los
ajusta después.

**Combos**: legacy los modela como servicios sueltos de 180 min. El sistema
nuevo tiene `ServicePackage` (partes + descuento). Primera pasada: migrarlos
tal cual como servicios de la categoría Combos — funcionan idéntico a hoy.
Convertirlos a paquetes de verdad es mejora posterior, no bloqueo.

### Empleadas → `resources` (+ `users` si van a entrar al panel)

7 activas, todas al 50%. `commission_percentage` 50 → `commission_rate`
0.50. **"Alejandra Castillo" existe dos veces** (una con el apellido en
`name`, otra separada): se fusionan y sus atenciones se re-apuntan.

Legacy **no tiene pivote servicio↔empleada** (cualquiera presta cualquiera):
en el nuevo se crea el vínculo con TODOS los servicios de entrada, y el
negocio recorta después ("Lucía no hace acrílicas" es configuración fina que
solo el negocio sabe).

### Horarios → `resource_schedules`

`work_shifts` (`days` JSON + `start_time`/`end_time`, turnos M/T por
persona) → filas por día de semana. Una empleada con turno de mañana y otro
de tarde el mismo día produce dos ventanas — el motor nuevo las soporta.
`time_slots` NO se migra: es la rejilla materializada que el motor nuevo
reemplaza calculando en vivo.

### Historial → `appointments` completadas

Cada `employee_service` FINALIZADO → una cita nueva con:

- `starts_at` = `date` + hora del slot (o `started_at`), convertido de -05 a UTC
- `status = completed`, `checked_out_at` = `finished_at`
- item con `final_price` (lo cobrado de verdad), `commission_amount`, servicio y recurso mapeados
- `payment_method_id` mapeado por nombre
- `source = 'admin'` y un marcador de migrada

Sin `resource_occupancy` (solo las citas futuras bloquean agenda). Con esto
la ficha muestra visitas, "ha gastado", última visita y "prefiere" reales.
CANCELADA (310) y ELIMINADO (514) no se migran: no aportan a la ficha y
ensucian los conteos.

### Citas futuras → por `BookingService`, nunca por SQL

Las ~100 agendadas a futuro entran una a una por `BookingService::book()`
(con `enforceSchedule` apagado si algún horario no coincide): así reclaman
su ocupación contra el índice único y la agenda nueva nace sin solapes. Si
una choca, se reporta para resolverla a mano — mejor un reporte de 3 citas
conflictivas que un solape silencioso.

### Fidelización → `loyalty_stamps`

356 tarjetas con 1–16+ sellos. Los sellos nuevos exigen `appointment_id`:
se enlazan a las últimas N visitas migradas de cada clienta (cada sello
NACIÓ de una visita; es reconstruir el vínculo, no inventarlo).

**Gap de producto a decidir**: legacy premia en escalera (5→10%, 10→10%,
15→15%, 20→10%, 25→15%, 30→producto, 35→25%); el nuevo tiene UN programa
(N sellos → un premio). Opciones: (a) configurar un solo nivel equivalente
y arrancar así, (b) extender el modelo nuevo a multinivel antes de migrar.
Los premios ya desbloquedos y sin usar (`loyalty_card_rewards` en
'available') hay que honrarlos como sea — son promesas hechas.

### Calificaciones → calificaciones del recurso

189 filas con nota de servicio, de empleada y de puntualidad + opinión. El
sistema nuevo muestra estrellas por profesional en la página pública: se
migra `employee_calification` ligada al recurso y la cita migrada.

## Lo que se queda en el legacy (y por qué)

**Contabilidad histórica** (gastos, cierres, nóminas, ventas de productos):
recomendación **no migrar**. Los reportes nuevos nacerían mezclando dos
sistemas con reglas de cálculo distintas, y el legacy queda como archivo de
consulta (la base no se borra). La contabilidad nueva arranca limpia el día
del corte. — *Decisión abierta si Alejandro quiere lo contrario.*

**Promociones** (0 activas en uso) — se recrean a mano en Campañas si hacen
falta. **Penalizaciones**: 0 filas, nada que migrar.

## Mecánica del script

- **Comando artisan en el sistema nuevo**: `luxury:importar {--dry-run}`.
  Corre EN el droplet: ambas bases viven en el mismo MySQL, así que se
  agrega una conexión `legacy` apuntando a `spa_app` con un usuario de
  **solo SELECT** — el legacy queda físicamente protegido de escrituras.
- **Idempotente por tabla de mapeo** `legacy_map (entity, legacy_id,
  new_id)`: correrlo dos veces actualiza en vez de duplicar, y permite
  correr hoy una prueba, seguir operando el legacy, y re-correr el día del
  corte trayendo solo el delta.
- **`--dry-run` primero, siempre**: reporta conteos, duplicados, teléfonos
  raros y citas futuras conflictivas sin escribir nada.
- **Orden**: negocio+sede → categorías → servicios → recursos → horarios →
  clientas → historial → citas futuras → sellos → calificaciones.
- Volumen total ~6.000 filas: minutos, no horas. Con `nice` igualmente.

## El día del corte (esbozo)

1. Migración de prueba ya (la base nueva está vacía: se puede borrar y
   repetir hasta que cuadre).
2. Verificación con el negocio: conteos, 5 fichas al azar contra el legacy,
   la agenda futura lado a lado.
3. Corte: re-correr (delta), apagar agendamiento en ManyChat, activar el
   wa.link / número según el plan de WhatsApp.
4. Legacy queda en solo-lectura como archivo. No se apaga el mismo día.

## Decisiones tomadas (2026-09-07)

### 1. Fidelización: se conserva la escalera, y se cierra una fuga

El dueño confirma el comportamiento: la manicurista, al cobrar, ve que la
clienta tiene premio y aplica el descuento. En el legacy eso funciona así
(verificado en `GamifiesClients.php` y `Employee/Service/Index.vue`):

- **Un sello por servicio finalizado**, sin mínimo de precio.
- **Escalera acumulativa**: los sellos NUNCA se reinician. Desbloqueo por
  igualdad exacta (`stamps == required_stamps`).
- **Un solo premio vivo**: al desbloquear el de 10, el de 5 sin redimir pasa
  a `expired`. Los premios no se acumulan.
- **La comisión se paga sobre precio de lista**: la manicurista no pierde
  nada por el descuento, y el local registra la diferencia como gasto
  "Retención cliente".

**Lo que el sistema nuevo ya tiene**: sellos, desbloqueo, el premio ofrecido
en el modal de cobro, marcado como usado, y la política de comisión sobre
lista (que ya venía como default para premios — coincide con el legacy).

**Lo que hay que agregar**: modo ESCALERA. El modelo nuevo es una tarjeta
repetible (junta N, consume N, vuelve a empezar); Luxury necesita hitos
acumulativos con premio distinto en cada uno.

#### La fuga que hay que cerrar

En el legacy **nadie marca el premio como usado**. Verificado en la base
real: 74 premios `available`, 60 `expired`, **0 `used`**, y **ninguna**
atención tiene `loyalty_card_reward_id` (siempre NULL).

Consecuencia: un premio desbloqueado en el sello 5 **se puede aplicar otra
vez** en los servicios 6, 7, 8 y 9. Solo muere al llegar al sello 10, cuando
el premio nuevo expira al viejo. En los datos hay 49 descuentos aplicados
(43 del 10%, 6 del 15%) sin ningún vínculo a un premio.

El sistema nuevo cierra el ciclo: al aplicarlo lo marca usado y lo enlaza a
la cita. **Es una mejora, no una regresión** — pero cambia lo que hoy pasa,
así que se dice antes y no después.

### 2. Contabilidad: sí, y sale casi gratis

El dueño quiere conservar al menos las ventas mensuales de este año.

**No hace falta migrar contabilidad**: al traer las 3.329 atenciones
terminadas con su `final_price` y su fecha de cobro, el reporte de Ventas
del sistema nuevo reconstruye los meses solo — y no solo 2026, sino todo el
histórico desde julio de 2024.

Lo que sí se migra aparte: **gastos** (476 filas, 160 de 2026 por 25.2M),
porque sin ellos la utilidad queda mal. NO se migran cierres de caja ni
nóminas: son registros operativos con las reglas de cálculo del otro
sistema, y el nuevo los computa desde sus propios datos.

Referencia de lo que debe cuadrar tras migrar (servicios finalizados):

| Mes 2026 | Servicios | Ingresos |
|---|---|---|
| Enero | 108 | 4.963.250 |
| Febrero | 140 | 5.964.800 |
| Marzo | 104 | 4.573.500 |
| Abril | 83 | 4.040.000 |
| Mayo | 64 | 2.972.000 |
| Junio | 97 | 4.844.000 |
| Julio | 73 | 2.950.500 |
| Agosto | 78 | 3.015.000 |

### 3. Combos: como paquetes, sin duplicar servicios

Los 4 combos son **exactamente la suma de servicios que ya existen**, sin
descuento:

| Combo | Precio | Partes |
|---|---|---|
| Tradi Manos + Pies | 50.000 | Tradicional (20k) + Pedi Jellyspa (30k) |
| Manos Semi + Pies Tradi | 75.000 | Semipermanente (45k) + Pedi Jellyspa (30k) |
| Semi Manos + Pies | 85.000 | Semipermanente (45k) + Pedi Jelly Semi (40k) |
| Semi Rubber + Semi Pies | 95.000 | Semi+Rubber (55k) + Pedi Jelly Semi (40k) |

Van como `ServicePackage` de las partes existentes. No se duplican.

**Ojo con la duración**: tres de los cuatro bloquean 180 min en legacy pero
sus partes suman 120. El paquete calcula la duración sumando partes, así que
sub-reservaría una hora. Se resuelve con el punto siguiente.

### 4. Las duraciones configuradas están cortas, y eso sí importa

Comparando la duración configurada contra la real (`started_at` →
`finished_at`) de los servicios con 5+ registros:

| Servicio | Configurada | Real (promedio) |
|---|---|---|
| Semipermanente | 60 | **115** |
| Semi + Rubber | 60 | 93 |
| Pedi - Jellyspa | 60 | 93 |
| Pedi + Jelly Spa + Semi | 60 | 85 |
| Tradicional | 60 | 77 |
| Tradi Manos + Pies | 120 | 170 |

En el legacy esto no dolía: la rejilla de slots era de 120 minutos fijos y
absorbía el error. **El motor nuevo respeta la duración al minuto**, así que
migrarlas tal cual haría que la agenda se atrase en cadena todo el día.

Salvedad honesta: `started_at`/`finished_at` dependen de cuándo la
manicurista abre y cierra el registro, así que el promedio puede estar
inflado. Pero el patrón es consistente en todos los servicios.

Recomendación: usar la migración para corregirlas, con estos datos a la
vista, y que el negocio confirme servicio por servicio.

### 5. Alejandra Castillo

Confirmado: es la misma persona. Se fusionan las dos fichas y sus
atenciones se re-apuntan a una sola.

## Decisiones que quedaron cerradas

1. **Fidelización**: ¿un solo nivel al arrancar, o extendemos a escalera
   antes de migrar? (Los premios disponibles se honran en ambos casos.)
2. **Contabilidad histórica**: ¿se queda en el legacy como archivo (recomendado)?
3. **Combos**: ¿migran como servicios (idéntico a hoy) y se convierten a
   paquetes después? (recomendado)
4. **Las 2 "Alejandra Castillo"**: confirmar que son la misma persona.
