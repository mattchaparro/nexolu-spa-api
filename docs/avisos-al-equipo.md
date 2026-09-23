# Avisos al equipo

> «Te agendaron una cita» y «se canceló una cita y esa hora te queda libre»,
> por WhatsApp, a quien va a atender.

## Por qué

Hasta ahora el equipo se enteraba mirando la agenda, y eso funciona hasta que
no: quien trabaja por comisión quiere saber que le agendaron sin abrir el
panel, y **una cancelación que nadie vio es una hora que alguien se queda
esperando en el salón**.

## Las tres reglas

### 1. Se enciende a propósito

`notify_team_whatsapp`, **apagado por defecto**. Superadmin → el negocio →
Configuración de agenda → «Avisarle por WhatsApp al equipo».

Prenderlo solo, en un deploy, sería empezar a escribirle al equipo de cada
negocio a nombre del salón sin que nadie lo pidiera.

### 2. Sin número no se avisa

El WhatsApp se carga en la ficha de cada persona (Equipo → editar). Si está
vacío, se cae al teléfono de su usuario del sistema; si tampoco hay, no se le
avisa — y **no es una falla**: muchas manicuristas no tienen ni cuenta.

Va en `resources.phone` y no en `users.phone` a propósito: quien atiende puede
no tener con qué entrar al sistema, y quien sí tiene puede usar otro número
para trabajar.

### 3. Uno por cita, tipo y persona

Lo garantiza el índice único de `messages` — que para esto hubo que ampliar de
`(appointment_id, kind)` a `(appointment_id, kind, to)`. Una cita de manos y
pies la atienden dos personas y las dos tienen que enterarse: con el índice
viejo, el aviso de la segunda chocaba con el de la primera y se descartaba en
silencio. Justo el mecanismo que garantiza no duplicar habría garantizado **no
avisar**.

Cada aviso nombra **solo el servicio de quien lo recibe**: si una hace las
manos y otra los pies, el de una no menciona el de la otra.

## Cómo sale

**Siempre como plantilla** (`cita_nueva_equipo`, `cita_cancelada_equipo`).
Quien atiende *recibe* del número del salón pero no le escribe, así que su
ventana de 24 horas está cerrada casi siempre — y como texto libre Meta
aceptaría el envío sin entregarlo.

Los cuerpos exactos están en [plantillas-whatsapp.md](plantillas-whatsapp.md).

## Qué lo dispara

`BookingService::book()`, `::cancel()` y `::reschedule()`, que son la única
puerta por la que se agenda, se cancela y se mueve: da igual si vino del
panel, de la página pública o del bot de WhatsApp.

Blindado igual que la lista de espera: si el aviso falla, la cita **igual**
queda agendada o cancelada. Negarse a agendar porque WhatsApp está caído
dejaría el mostrador atascado por algo que no depende de nadie ahí.

## Cuando la mueven

Tres casos, y por eso no es un solo aviso:

| Quién | Qué recibe | Con qué hora |
|---|---|---|
| La misma persona, otra hora | «Te movieron una cita», con el antes y el después | Las dos |
| La que **pierde** la cita | El aviso de cancelación: esa hora le queda libre | La **vieja** — es el espacio que recupera |
| La que la **recibe** | El de cita nueva: para ella es una cita nueva | La nueva |

Quien la recibe no se entera de con quién estaba antes, y quien la pierde no
necesita saber a qué hora quedó: lo que cada una necesita es su propia agenda.

Mover una cita a la **misma hora y con la misma persona** no avisa nada.
Avisar de una mudanza que no movió nada es ruido puro.

### Por qué este aviso no se cuelga de la cita

Los otros dos sí (es lo que garantiza no mandarlos dos veces). El de mudanza
no, y es deliberado: **una cita se puede mover dos veces**, y el índice único
dejaría pasar solo el primer aviso — la segunda mudanza se descartaría en
silencio y ella se aparecería a la hora vieja.

El evento acá es la **mudanza**, no la cita. El costo es que ese mensaje no
queda enlazado a la cita en la bandeja; entre eso y no avisar, se prefiere
avisar.
