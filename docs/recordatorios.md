# Recordatorios de cita

## Qué hace

Cada 15 minutos busca las citas que arrancan dentro de las próximas N horas
(24 por defecto) y les prepara el recordatorio.

**"Prepara" y no "manda"**: en modo manual quedan en «Mensajes por enviar» para
que una persona los envíe; en automático salen solos. El comando no sabe la
diferencia y no tiene por qué — eso lo decide `MessageDispatcher`.

```bash
php artisan recordatorios:preparar
php artisan recordatorios:preparar --dry-run     # a quién le tocaría, sin tocar nada
php artisan recordatorios:preparar --business=3  # sólo uno
```

## Lo que hay que hacer en el servidor

**Nada de esto corre solo sin el cron de Laravel.** Y el síntoma de que falta es
silencioso: nadie recibe recordatorios y nadie se entera.

```bash
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

Un solo `schedule:run` cada minuto: Laravel decide qué toca. No hay que agregar
una entrada por comando.

Para comprobar que quedó:

```bash
php artisan schedule:list
```

## Las tres reglas, y por qué

### 1. Ventana abierta, no instante exacto

Se buscan las citas que arrancan **dentro de** las próximas N horas, no las que
arrancan exactamente en N.

Si el comando no corrió —servidor caído, cron mal puesto, un despliegue— la
siguiente corrida las recupera. Preguntar por un instante exacto significa que
un minuto de caída son las citas de ese minuto sin avisar, y **nadie se entera
nunca**: no hay error, no hay log, sólo clientas que no recibieron nada.

### 2. No se recuerda lo que se agendó tarde

Si la cita se creó **después** del momento en que tocaba recordarla, no hay
recordatorio. Literalmente: `created_at <= starts_at − N horas`.

Alguien que reservó hace dos horas para mañana no necesita que le recuerden algo
que acaba de decidir. Sin esta regla, cada reserva de última hora dispara un
mensaje redundante que lee como spam — y lo que se pierde ahí no es un mensaje,
es la disposición de esa persona a leer los siguientes.

### 3. Uno por cita

Garantizado por el índice único `(appointment_id, kind)` de `messages`, no por
una bandera en la cita.

Es la lección de `gamification:recalculate` en Blue Souls: los contadores se
desincronizan y hay que escribir un comando para repararlos. Una restricción no
se desincroniza. Eso es lo que permite programarlo cada 15 minutos sin miedo.

## Lo que se omite sin ser un error

Las citas **sin teléfono**. Es normalísimo —alguien se agendó por el mostrador y
nadie anotó el número— y se reportan aparte:

```
Listos: 12. Sin teléfono o ya avisados: 3.
```

Contarlas como fallos haría que el comando se vea rojo todos los días, y que
nadie mire la salida el día que de verdad falle algo.

## Ajustes por negocio

| Ajuste | Dónde | Default |
|---|---|---|
| Cuántas horas antes | `scheduling_settings.reminder_hours_before` | 24 |
| El texto | `scheduling_settings.reminder_template` | ver abajo |
| Si están encendidos | bandera `reminders` | según plan |

`reminder_hours_before` en **0** apaga los recordatorios sin perder la función.

**24 y no 2**: el recordatorio sirve para que quien no va a poder avise A
TIEMPO, no para que se acuerde de correr. Dos horas antes ya no alcanza a vender
ese hueco otra vez, que es de lo que se trata bajar las inasistencias.

### La plantilla

```
Hola {cliente}, te recordamos tu cita en {negocio} el {fecha} a las {hora}
con {profesional}. Si necesitas cambiarla: {mis_citas}
```

**`{mis_citas}` es la parte que importa.** Ahí está la diferencia entre un
recordatorio que sirve y uno que no: si la persona no va a poder, tiene que
poder MOVERLA en ese momento, no acordarse de llamar mañana. Un recordatorio sin
salida sólo consigue que la inasistencia llegue avisada.

## Lo que falta

- **El envío es síncrono.** No hay worker de colas; un job encolado que nadie
  procesa se ve igual que un mensaje perdido. Cuando exista el worker, cambia
  una línea de `MessageDispatcher::queue()`.
- **Un solo recordatorio por cita.** Dos (día antes + dos horas antes) chocarían
  con el índice único: haría falta que el `kind` lleve el desfase. Nadie lo ha
  pedido todavía.
- **Recordatorio de retoque** —"ya va siendo hora de tus uñas"— es otra cosa: no
  cuelga de una cita futura sino de la última visita. Sería otro `kind` y otro
  servicio.
