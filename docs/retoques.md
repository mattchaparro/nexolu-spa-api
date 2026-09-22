# Recordatorio de retoque

> "Ya casi te toca retoque." Es el mensaje que más citas devuelve, y el que
> mejor justifica tener la agenda: nadie más sabe QUÉ se hizo, CUÁNDO y CON
> QUIÉN.

## Qué hace

Cada hora busca a las clientas que ya cumplieron los días de retoque de su
**última** visita y les prepara el mensaje.

```bash
php artisan retoques:recordar
php artisan retoques:recordar --dry-run      # a quién le tocaría, sin preparar nada
php artisan retoques:recordar --business=3   # sólo uno
php artisan retoques:recordar --ahora        # ignora la hora configurada
```

Corre cada hora porque **cada negocio elige a qué hora LOCAL sale el suyo**
(`retouch_reminder_hour`, 10 de la mañana por defecto). Un cron para todos los
husos, en vez de una tarea programada por negocio.

**Las 10 de la mañana** y no la noche: ya desayunó y todavía puede cuadrar el
día. De noche se lee y se olvida.

## Las reglas, y por qué

### 1. Su última visita, no cualquiera

Si volvió la semana pasada por otra cosa, el retoque que toca es el de esa
visita. Escribirle por un servicio que ya se rehizo se lee como que el salón no
sabe quién es.

### 2. A quien no tiene cita

Quien ya volvió a agendar no necesita que le recuerden volver.

### 3. Ventana, no instante

Se busca a quien cumple los días **hoy o en los últimos tres**. Si el cron
estuvo caído el fin de semana, el lunes salen los de viernes, sábado y domingo
en vez de perderse para siempre — sin error, sin log, sólo clientas que no
recibieron nada.

Más atrás de tres días ya no es un recordatorio de retoque, es un "hace mucho
no vienes": otro mensaje, otra decisión.

### 4. Uno por cita

Garantizado por el índice único `(appointment_id, kind)` de `messages`, igual
que el recordatorio de cita. Una restricción no se desincroniza.

## Cada cuántos días

| Dónde | Qué significa |
|---|---|
| `services.retouch_days` | Lo de ese servicio |
| `spa.defaults.retouch_days` | 20 días, cuando el servicio no dice nada |
| `services.retouch_days = 0` | Este servicio **no** se retoca |

Veinte días es lo que dura un semipermanente antes de verse crecido. El **0**
es para lo que no tiene retoque: un retiro, una reparación.

## La plantilla

Sale **semanas** después de la última conversación, así que va siempre como
plantilla: fuera de la ventana de 24h Meta descarta el texto libre sin avisar.

En el WhatsApp Manager, categoría **marketing**, idioma **es**:

- Nombre: `retoque_recordatorio`
- **Encabezado** (tipo texto): `Se acerca tu retoque` — WhatsApp lo pone en
  negrilla solo. Es lo único que se ve en la notificación del teléfono, así
  que ahí va de qué se trata, no un saludo.
- Cuerpo: `¡Hola {{1}}! 👋 Tu última cita en {{2}} fue de {{3}} y ya va siendo hora del retoque 💅`
- Variables, **en este orden**: `{{1}}` cliente · `{{2}}` negocio · `{{3}}` servicio
- **Pie** (texto fijo, sin variables): `Mensaje automático · Responde y te atendemos`
  — el pie va en gris pequeño y evita que el primer "¡Hola!" se lea como que
  hay alguien escribiendo en vivo.
- Botones de respuesta rápida, **con estos textos exactos**:
  1. `Agendar retoque`
  2. `Empezar de cero`
  3. `Darme de baja`

**Corto a propósito.** El de ManyChat eran cinco párrafos — "nos encanta
cuidar de ti", "no dejes pasar más tiempo sin consentirte" — y lo que la
clienta necesita saber cabe en dos líneas: qué se hizo y que puede agendar. Lo
demás se lee como publicidad, y la publicidad se salta.

## Darse de baja

El tercer botón apaga `clients.accepts_marketing`, que es **la misma llave que
ya frena las difusiones**: una sola, para que darse de baja no haya que
pedirlo otra vez por cada tipo de mensaje que se nos ocurra después. El
importador de Luxury ya la trae del sistema viejo, así que quien se dio de
baja en ManyChat sigue de baja acá.

No es cortesía: es lo que separa un recordatorio de un spam. Quien no puede
salirse bloquea el número — y un bloqueo se lleva por delante también los
recordatorios de su propia cita, y la calidad del número para todas las demás.

**Lo que sigue llegando** es lo de sus propias citas: confirmación y
recordatorio. No es publicidad, es el servicio que ella pidió, y callarlo la
deja sin saber a qué hora es su cita. El mensaje de despedida se lo dice, y
trae un botón «Volver a recibir»: salirse y volver tienen que costar lo mismo.

El nombre del negocio va adentro a propósito: con el número compartido el
mensaje no llega del teléfono del spa, y si el texto no dice de quién es, la
clienta recibe publicidad de un desconocido.

**Los textos de los botones son la interfaz.** Meta devuelve el título tal cual
por el webhook y `GuidedEntry` compara contra `RETOUCH` y `FROM_SCRATCH`.
Cambiar el texto en Meta sin cambiar la constante deja los botones muertos: la
clienta toca y no pasa nada.

## El atajo: «Agendar retoque»

Aquí está lo que en ManyChat no funcionaba. Allá ese botón devolvía al inicio y
tocaba repetir todo el procedimiento — servicio, profesional, día, hora — para
llegar a lo mismo de siempre.

Aquí no: el servicio y la manicurista salen de su última visita (que es lo que
el mensaje le acaba de nombrar) y **sólo se le pregunta el día**. Es el único
dato que el salón no puede saber.

La profesional se precarga **sólo si sigue activa y se puede reservar con
ella**. Prometerle la de siempre y que no esté es peor que no ofrecerla.

Y si quería otra cosa, «Empezar de cero» borra el pedido y abre el catálogo
como a quien llega nueva.
