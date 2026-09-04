# Publicaciones en redes

## Qué hace

Llena la hoja en blanco. Un calendario editorial cualquiera te pregunta «¿qué
quieres publicar hoy?», que es exactamente la pregunta que nadie sabe contestar
a las once de la noche. Este la contesta con lo que ya pasó en el negocio:

- ayer salió una clienta feliz y **hay foto con permiso**,
- el jueves quedan cuatro horas muertas en la agenda,
- el pedicure spa no se vende hace mes y medio.

Las tres son noticias, y ninguna requiere que a nadie se le ocurra nada. Es la
razón por la que este módulo vive dentro de la API de agenda y no en una
herramienta suelta: el dato que hace falta ya está en esta base.

```bash
php artisan publicaciones:proponer     # busca ideas (diario, 7:00)
php artisan publicaciones:despachar    # libera lo que cumplió su hora (cada 15 min)

php artisan publicaciones:proponer --business=3   # sólo un negocio
```

Requiere la bandera `social_posts` (plan Full) y el permiso
`publicaciones.gestionar`.

## Lo que hay que hacer en el servidor

**Nada de esto corre solo sin el cron de Laravel**, igual que los
recordatorios, y el síntoma de que falta es igual de silencioso: la bandeja
nunca se llena y nadie sabe por qué.

```bash
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

Un solo `schedule:run` cada minuto. Para comprobar que quedó: `php artisan
schedule:list`.

## El ciclo

```
draft  ──►  scheduled  ──►  ready  ──►  published
  │            (una persona     (el reloj)   (una persona)
  │             aprueba)
  └──►  discarded
```

- **draft** — una idea. Puede no tener texto todavía, y no tiene fecha.
- **scheduled** — alguien la aprobó y le puso hora. Es el único estado que el
  reloj mira.
- **ready** — le llegó la hora. Está esperando que alguien la publique.
- **published** — salió. Lo marca la persona que la pegó en Instagram.
- **discarded** — no va. Se guarda igual: saber que se descartó es lo que
  impide que el planificador la vuelva a proponer mañana.

## Las decisiones que no son obvias

### 1. El sistema no publica

El reloj mueve `scheduled` a `ready` y **ahí se detiene**. La última tecla la
toca una persona, desde el panel, con el texto y la foto adelante.

Hay una prueba (`test_el_reloj_nunca_publica`) que falla si alguien agrega esa
línea. No es pereza; son tres razones:

- **La cuenta de Instagram de un spa de barrio *es* el negocio.** No es un canal
  más: es donde la clienta nueva decide si entra. Un texto raro publicado a las
  tres de la mañana sin que nadie lo mirara cuesta más que las diez
  publicaciones que no salieron.
- **El texto lo escribió un modelo.** Aprobarlo el lunes no es lo mismo que
  verlo publicado el jueves: entre las dos cosas el negocio cambió de promoción,
  se enfermó la manicurista y el hueco del jueves se llenó.
- **Y la parte aburrida:** publicar en nombre de otro en Meta exige una app
  revisada y un token que hay que rotar. Cuando exista, se enchufa donde
  `PostDispatcher` deja el hueco — la columna `external_ref` ya está para eso.

Lo que sí automatiza el reloj es **acordarse**: a la hora que se eligió, la
publicación aparece en «listas para publicar» y deja de estar en el futuro.

### 2. Ninguna foto de una clienta sale sin su permiso

Es la regla que sostiene el módulo entero.

`client_photos.marketing_consent_at` es lo **único** que autoriza a sacar una
foto de la ficha. Que la foto se vea muy bien, que la clienta sea simpática o
que «total, no se le ve la cara» no son permisos.

- Se pregunta **al subir la foto** (`marketing_consent` en el alta), que es
  cuando la clienta está ahí mirándose las manos. Volver a buscarla dos semanas
  después, cuando alguien arma el calendario, es como se termina publicando sin
  preguntar.
- **Ausente = no.** El silencio no es un permiso.
- Se puede **retirar** en cualquier momento
  (`POST /clients/photos/{foto}/marketing-consent` con `allowed: false`), y al
  retirarlo se descartan las publicaciones de esa foto que todavía no salieron.
  Lo que ya se publicó no se puede despublicar desde acá, y decir lo contrario
  sería mentirle al negocio.
- El listado de fotos publicables (`/social-posts/photo-pool`) filtra por
  consentimiento y **no acepta ningún parámetro para saltárselo**. Una
  salvaguarda que se puede desactivar con un parámetro no es una salvaguarda.
- Ese listado **tampoco da el nombre de la clienta**. Quien arma la publicación
  no lo necesita, y no dar el dato es más barato que confiar en que no se use.

### 3. El nombre de la clienta no viaja al modelo

El permiso fue para la foto de sus uñas, no para que su nombre llegue a un
servicio de IA. Por eso el encargo que se le manda **no lo incluye** — no basta
con pedirle al modelo que no lo use: un dato que no viaja no se puede filtrar.

Por lo mismo, el encargo va sin permisos y sin `is_admin`, aunque lo pida la
dueña: escribir un texto no necesita tocar la agenda, y una llamada que no puede
ejecutar herramientas no puede ser desviada a ejecutarlas.

### 4. El modelo no inventa precios

Es la forma más fácil de que esto haga daño de verdad: «50% de descuento esta
semana» en el Instagram del negocio es una promesa que alguien va a llegar a
cobrar al mostrador. Los precios que puede mencionar son los que se le pasan; si
no se le pasa ninguno, no dice ninguno.

### 5. El texto se escribe cuando alguien lo pide, no al proponer

El planificador deja la idea con su ángulo y su material —la foto, el servicio,
el día— y **nada de texto**. Redactar las cinco propuestas de cada día sería
pagarle al IA Core por ciento cincuenta textos al mes para usar diez.

El botón de «escríbeme el texto» llama a `POST /social-posts/{id}/compose`, una
publicación a la vez.

Y si el IA Core no contesta, el módulo **sigue sirviendo**: se avisa que no se
pudo y el negocio escribe su texto a mano, que es lo que hacía antes de que esto
existiera. Un módulo de publicaciones que se cae porque un servicio de IA está
caído no es un módulo de publicaciones.

### 6. Idempotencia por `idea_key`, no por una bandera

El planificador corre a diario sobre una ventana abierta —los próximos cinco
días—, así que sin esto volvería a proponer el hueco del jueves cada día hasta
que llegue el jueves.

La huella de cada idea (`hueco:1:2026-09-17`, `foto:412`,
`servicio:8:2026-W38`) es **única por negocio**, y esa restricción de la base
*es* el mecanismo. Misma lección que los recordatorios: una restricción no se
desincroniza, un contador sí.

### 7. La bandeja llena calla al planificador

Doce propuestas sin mirar no necesitan la trece: necesitan que alguien las mire.
Cuando se llena (`spa.social.max_open_drafts`) el planificador se calla y espera
— que es lo contrario de cómo una función que ayuda se convierte en una que se
ignora.

Y las propuestas **automáticas** que nadie tocó en diez días se descartan solas.
Las que escribió una persona no se descartan nunca: una idea guardada en enero
para el día de la madre sigue siendo una idea en abril.

## De dónde salen las ideas

| Ángulo | Señal | Huella |
|---|---|---|
| `trabajo` | Foto de la ficha, con permiso, de los últimos 14 días | `foto:{id}` |
| `hueco` | Un día entre mañana y +5 con más de 3h libres en el equipo | `hueco:{sede}:{fecha}` |
| `servicio` | Servicio activo, que **ya se vendió alguna vez**, sin venderse hace 6 semanas | `servicio:{id}:{año-semana}` |
| `equipo`, `libre` | No se proponen solos: los escribe una persona | — |

Un servicio que **nunca** se vendió no se propone. No es un servicio olvidado:
es una fila del catálogo que alguien creó por error, y anunciarla es hacerle
publicidad a un error.

Los huecos se miran **desde mañana**. Hoy no alcanza —entre que alguien aprueba,
publica y alguien más lo ve y escribe, el día se acabó— y proponer algo que ya
no da tiempo entrena al negocio a ignorar el módulo.

Del hueco se anuncia **el día y no las horas**: las horas cambian entre que el
texto se escribe y que alguien lo publica —una reserva en el medio y el texto
queda mintiendo— así que se invita a escribir, que además abre la conversación
donde el agente de WhatsApp ya sabe agendar.

## Los números

Viven en `config/spa.php`, bajo `social`. Son de plataforma y no del negocio a
propósito: el control real que tiene el negocio no es cuántas propuestas recibe
—son propuestas, no salen solas— sino cuáles aprueba.

| Clave | Por defecto | Qué decide |
|---|---|---|
| `max_open_drafts` | 12 | Cuántas propuestas sin revisar antes de callarse |
| `draft_stale_days` | 10 | Cuándo se descarta sola una propuesta automática |
| `work_photo_days` | 14 | Hasta cuándo una foto sigue siendo noticia |
| `work_photos_per_run` | 2 | Fotos por corrida |
| `gap_horizon_days` | 5 | Hasta dónde se miran los huecos |
| `gap_min_free_min` | 180 | Minutos libres para que un día cuente como hueco |
| `gaps_per_run` | 2 | Huecos por corrida |
| `service_quiet_weeks` | 6 | Cuánto sin venderse para que valga recordarlo |

## Lo que todavía no está

- **No publica.** Ver arriba; es una decisión, no una tarea pendiente. Lo que sí
  está pendiente es el canal automático de Meta, cuando el producto lo pida.
- **No avisa por WhatsApp** que hay publicaciones listas. Un aviso fuera de la
  ventana de 24h necesita una plantilla aprobada por Meta, y una plantilla que
  no existe falla en silencio. Por ahora el aviso es el contador de la pantalla.
- **No mide.** Cuántas personas escribieron después de una publicación es el
  dato que diría si esto sirve, y hoy no se puede saber sin la API de la red.
