# Cierre de servicio

## Qué hace

La cita empezó a las 2, el semipermanente dura noventa minutos, y a las 3:30 la
clienta se va. La manicurista sigue con la siguiente, y el servicio queda sin
registrar hasta que alguien —en el cierre del día— intenta reconstruir de
memoria quién atendió qué.

Esto cierra ese hueco con tres piezas:

1. **El aviso.** A los N minutos de que el servicio termina, a quien atendió le
   llega un WhatsApp: *«terminaste X de Y a las 3:30. Regístralo y sube la foto
   del trabajo.»* Y el mismo pendiente le aparece en «Mi día».
2. **La foto del trabajo**, ligada al servicio que la produjo.
3. **El comprobante de pago**, ligado a la cita.

Las dos últimas son las imágenes que hoy viajan por el grupo de WhatsApp junto
a un texto —«uñas semipermanente de cuarenta mil»— **cuyo contenido ya está en
esta base**. Con las imágenes acá, ese mensaje deja de hacer falta.

```bash
php artisan servicios:recordar
php artisan servicios:recordar --dry-run      # a quién le tocaría, sin avisar
php artisan servicios:recordar --business=3
```

Necesita el cron de Laravel (`schedule:run` cada minuto), igual que los
recordatorios.

## Viene apagado

`service_done_reminder_min` arranca en **0**, y con eso el comando no manda
nada. No es prudencia decorativa: encender un aviso al equipo por defecto es
mandarle WhatsApp a las empleadas de un negocio, **a nombre del negocio**, sin
que nadie lo haya pedido.

Se enciende negocio por negocio, en sus ajustes de agenda.

## Las decisiones que no son obvias

### 1. El ancla es `service_ends_at` del ÚLTIMO item

Las dos mitades de esa frase importan.

**`service_ends_at` y no `ends_at`:** el segundo incluye el buffer de limpieza.
Avisar cuando termina el buffer es avisar cuando la profesional ya arrancó con
la siguiente clienta.

**El último item y no el primero** —al revés que `NotifyStaffAction`. Ahí el
aviso es «tu cita cambió» y le toca a quien la abre; acá es «el trabajo quedó
listo, fotografíalo», y eso sólo lo puede hacer quien todavía tiene a la clienta
enfrente. En una cita encadenada —manos con María, pies con Luisa— avisar al
terminar el primero sería interrumpir un trabajo que sigue en curso.

### 2. Ventana abierta, pero acotada por los dos lados

Abierta hacia atrás para que una corrida perdida se recupere sola, igual que los
recordatorios: si el servidor estuvo caído dos horas, el aviso sale igual.

Pero con tope (`service_done_reminder_max_age_min`, 4h por defecto). Un aviso a
las nueve de la noche sobre lo de las tres no lo va a resolver nadie, y sólo
entrena a ignorar el siguiente.

### 3. Tipo de mensaje propio, y es importante

`messages` tiene índice único `(appointment_id, kind)`. Si este aviso usara
`KIND_STAFF` —el de las acciones de etapa— una cita que ya movió de etapa se
tragaría el segundo **en silencio**. El síntoma sería «a veces no me llega», que
es la clase de bug que nadie reporta bien.

Por eso existe `Message::KIND_SERVICE_DONE`.

### 4. La foto se pide, nunca se exige

Bloquear el cobro por una foto que falta termina siempre en uno de dos sitios:
una foto cualquiera subida para poder cobrar, o una caja que no cierra a las
nueve de la noche. Un pendiente visible convence mejor que un candado.

### 5. Dos niveles para decidir si se pide

**Por negocio** (`service_photo_policy`): una barbería lo deja en `none`. Ahí no
se acostumbra fotografiar la cara de nadie, que es distinto de fotografiar unas
manos.

**Por servicio** (`services.requires_photo`): un semipermanente transparente no
produce nada que valga la pena fotografiar. Pedir foto de todo entrena a la
gente a subir cualquier cosa.

`requires_photo` arranca en `true` y aun así no cambia nada para nadie, porque
el interruptor maestro del negocio viene apagado. Cuando lo enciendan, todos sus
servicios piden foto y apagan los pocos que no la necesitan. Al revés —arrancar
en `false`— el negocio enciende la política, no pasa nada, y concluye que la
función no sirve.

### 6. Subir la foto NO exige acceso a la base de clientes

`ClientProfileController::storePhoto` pide `clientes.gestionar`, y el rol de
profesional **no lo tiene**: la base de clientes con teléfonos es el activo del
negocio y no se reparte al equipo entero.

Pero fotografiar el trabajo que uno acaba de hacer no es administrar la ficha de
nadie. Si la única ruta fuera esa, habría que darle acceso a toda la clientela
para una función pequeña — y alguien lo haría. Por eso existe
`ServiceClosingController`, donde **el límite es la agenda, no la ficha**: se
puede subir a una cita que uno atendió. Quien tiene `citas.ver_todas` puede en
cualquiera, porque es quien cobra por las demás.

### 7. Sin inteligencia artificial

Esta imagen es la **evidencia** de cómo quedó el trabajo: lo que se mira para
evaluarlo y lo que la profesional consulta la próxima vez. Una versión retocada
no sirve para ninguna de las dos cosas.

Embellecer es una decisión aparte, sobre una **copia**, y sólo si esa foto llega
a una publicación. El original nunca se toca.

### 8. El comprobante hace verificable el cierre del día

El efectivo se cuenta en el cajón. Una transferencia no se puede contar: sin
comprobante, el cierre cuadra contra lo que alguien **dijo** que entró, que es
exactamente lo que el cierre existe para no hacer.

Por eso la política útil es `non_cash`: se pide sólo cuando el medio de pago no
cuenta como efectivo (`payment_methods.counts_as_cash`).

## Los ajustes

Viven en `businesses.scheduling_settings`, con default de plataforma en
`config/spa.php`. Se leen siempre por `Business::schedulingSetting()`.

| Clave | Por defecto | Qué decide |
|---|---|---|
| `service_done_reminder_min` | `0` (apagado) | Minutos tras terminar el servicio para avisar |
| `service_done_reminder_max_age_min` | `240` | Hasta cuándo vale la pena avisar |
| `service_photo_policy` | `none` | `none` \| `ask` — si se pide la foto del trabajo |
| `payment_proof_policy` | `none` | `none` \| `non_cash` \| `always` |

Y por servicio: `services.requires_photo`.

## Lo que todavía no está

- **La foto no llega por WhatsApp.** La manicurista la sube desde «Mi día». Que
  llegue por chat exige que `nexolu-comms-api` sepa descargar medios entrantes
  (Meta manda un `media_id`, no la imagen), y eso vive en otro repo.
- **Un comprobante por cita.** Los pagos partidos no están modelados hoy.
- **No se valida el comprobante.** Es una imagen que alguien mira, no un
  cotejo contra el banco.
