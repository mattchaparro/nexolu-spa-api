# Las plantillas de WhatsApp

> Fuera de las 24 horas siguientes al último mensaje de la clienta, Meta
> **acepta** el texto libre y **no lo entrega**. Sin error y sin rebote: el
> mensaje se ve enviado en la bandeja y nunca llegó. Por eso todo lo que el
> salón manda por su cuenta necesita una plantilla aprobada.

## Cómo se elige una u otra

Un mensaje puede traer las dos cosas a la vez —texto y plantilla— y
`MessageDispatcher` elige **al enviar**, que es el único momento en que se
sabe si la ventana está abierta:

| Ventana | Qué sale | Por qué |
|---|---|---|
| Abierta (escribió hace < 24h) | El **texto** | Lleva lo que una plantilla no puede: el enlace de «mis citas», el Instagram, los renglones que sobran cuando no hay precio |
| Cerrada | La **plantilla** | Es lo único que Meta entrega |
| Sin plantilla | El texto igual | Es lo que el negocio ve en «Mensajes por enviar» para mandarlo a mano |

Por eso el texto se guarda **siempre**, aunque salga la plantilla: es lo que
se lee en la bandeja y lo que una persona copia en modo manual. De
"Hola Carolina, tu cita del jueves…" no se sacan de vuelta las variables.

## Las nueve, y cómo crearlas

En el WhatsApp Manager, idioma **es**. El nombre y **el orden de las
variables** tienen que ser exactos: Meta no recibe nombres, recibe una lista
posicional, y un parámetro en el puesto equivocado manda "tu cita en miércoles
3 a las Luxury Nails" sin que nada falle.

El **nombre del negocio va adentro** de las de la clienta a propósito: con el número
compartido de Nexolu el mensaje no sale del teléfono del spa, así que si el
texto no dice de quién es, la clienta recibe un mensaje de un desconocido.

### 1. `confirmacion_cita` — categoría *utility*

La cita que agenda el salón desde el panel. Es el único aviso que sale sin que
la clienta haya escrito: se confirma la agenda del día siguiente, o se agenda a
alguien que no escribe hace un mes.

```
¡Tu cita quedó confirmada! ✅

📅 Día: *{{1}}*
⏰ Hora: *{{2}}*
💅 Servicio: *{{3}}*
💵 Precio: *{{4}}*
🙋‍♀️ Te atiende: *{{5}}*

Gracias por agendar en *{{6}}* 🌟
```

`{{1}}` día · `{{2}}` hora · `{{3}}` servicio · `{{4}}` precio · `{{5}}` quién
atiende · `{{6}}` negocio.

**Botones de respuesta rápida**, con estos textos exactos:

1. `Info de garantías`
2. `Recomendaciones`
3. `Cancelaciones`

Son los mismos tres que el bot ofrece en un segundo mensaje cuando la clienta
agenda por el chat, y los que ella ya conoce de ManyChat. Dentro de la ventana
siguen saliendo así --en su propio mensaje, y **sólo los que el negocio tenga
escritos**--; fuera de la ventana viajan dentro de la plantilla, porque un
segundo mensaje de texto no se entregaría.

Las respuestas salen de la **base de conocimiento** (Configuración → «Enséñale
al bot»), así que hay que escribirlas antes de crear la plantilla: en el
mensaje de plantilla los tres botones aparecen siempre, aunque el negocio no
haya escrito nada, y un botón que contesta "no tengo esa información" es peor
que no estar.

**El Instagram no cabe en la plantilla**, pero no se pierde: tocar cualquiera
de esos botones es un mensaje entrante, y eso ABRE la ventana de 24 horas. A
partir de ahí el bot vuelve a responder en texto libre, con el Instagram y
todo lo demás.

Es **el mismo mensaje** que recibe quien agenda por el bot, con dos diferencias
que la plantilla no puede evitar: los renglones son fijos (si no hay precio
dice «Te lo confirmamos en el salón», porque un parámetro vacío hace que Meta
rechace el envío entero) y no lleva el Instagram (la plantilla es de la WABA de
Nexolu y la comparten todos los negocios, así que no puede llevar el de uno).

### 2. `gracias_por_tu_visita` — categoría *utility*

Sale cuando la manicurista termina (la cita pasa a «Lista y cobrada»). Casi
nunca hay ventana abierta: la clienta **vino al salón**, no escribió por
WhatsApp. Sin esta plantilla, el mensaje que más hace volver no se entrega.

```
*Gracias por tu visita*

👋 ¡Hola, {{1}}!
Gracias por visitarnos 💅

🧾 Servicio: *{{2}}*
📅 Fecha: *{{3}}*

*Info de tu tarjeta*
🎯 ¡Ya tienes *{{4}} de {{5}}* sellos!
🎁 Próximo: *{{6}}*
```

`{{1}}` cliente · `{{2}}` servicio · `{{3}}` fecha · `{{4}}` sellos que lleva ·
`{{5}}` sellos que necesita · `{{6}}` próximo premio.

**Botones**: `Calificar servicio` y `Mi tarjeta`.

La tarjeta es la parte que hace volver: «te faltan 3 sellos» es una razón
concreta para agendar otra vez, y es información que la clienta no tiene de
otra forma — en el mostrador nadie se la dice.

> ⚠️ **Los renglones de la tarjeta son fijos.** Un negocio sin programa de
> sellos recibiría "¡Ya tienes 0 de 0 sellos!", así que el código **no manda
> la plantilla** cuando no hay programa activo: ese negocio recibe solo el
> texto, que dentro de la ventana llega igual. Luxury sí tiene programa.

### 3. `recordatorio_cita` — categoría *utility*

```
Hola {{1}}, te recordamos tu cita en {{2}} el {{3}} a las {{4}}.
```

`{{1}}` cliente · `{{2}}` negocio · `{{3}}` fecha · `{{4}}` hora.

Dentro de la ventana sale el texto del negocio, que **sí** lleva el enlace de
«mis citas» — y ahí está la diferencia entre un recordatorio que sirve y uno
que no: si la persona no va a poder, tiene que poder moverla en ese momento.

**Botones**, con estos textos exactos:

1. `Confirmo que voy`
2. `Reagendar`
3. `Cancelar cita`

Fuera de la ventana —que es el caso normal de un recordatorio— son la única
salida que tiene: sin ellos llega un aviso que no se puede contestar.

`Confirmo que voy` deja la cita **confirmada** en el tablero, que es lo que
hoy se hace llamando una por una. `Cancelar cita` pregunta antes (y avisa la
multa si es tardía). `Reagendar` abre las horas.

### 4. `retoque_recordatorio` — categoría *marketing*

Ver [retoques.md](retoques.md): encabezado, cuerpo, pie y los tres botones
(`Agendar retoque`, `Empezar de cero`, `Darme de baja`), cuyos textos son la
interfaz y no se pueden cambiar en Meta sin cambiarlos en el código.

### 5. `cita_cancelada` — categoría *utility*

Cuando cancela el **salón**: se enfermó quien atendía, se cayó la luz. Pasa
fuera de toda conversación, así que sin plantilla la clienta se aparece a una
cita que ya no existe.

```
Hola {{1}}, tu cita del {{2}} a las {{3}} en {{4}} quedó cancelada.
Escríbenos y la reagendamos.
```

`{{1}}` cliente · `{{2}}` fecha · `{{3}}` hora · `{{4}}` negocio.

**Botón**: `Agendar` — para que reagendar sea un toque y no un mensaje que
ella tiene que redactar.

### 6. `cupo_disponible` — categoría *utility*

La lista de espera. **El más urgente de todos**: el cupo es para quien lo tome
primero, y llega días después de que la persona se apuntó, así que su ventana
lleva rato cerrada.

```
¡Hola {{1}}! Se liberó un cupo para {{2}}: {{3}} a las {{4}} en {{5}}.
Es para quien lo tome primero.
```

`{{1}}` cliente · `{{2}}` servicio · `{{3}}` fecha · `{{4}}` hora ·
`{{5}}` negocio.

**Botón**: `Agendar`.

> El **enlace** para tomar el cupo no cabe en la plantilla, y es justo lo que
> el texto libre sí lleva. No se pierde: tocar el botón abre la ventana de 24
> horas y el bot lo manda. Ese enlace muestra los cupos vigentes **en vivo**,
> así que sirve aunque ese cupo ya se haya ido.

### 7, 8 y 9. Los avisos al EQUIPO — categoría *utility*

No son para la clienta: son para quien atiende. Van fuera de la ventana
siempre --la manicurista recibe del número del salón, pero no le escribe--,
así que sin plantilla no existen.

`cita_nueva_equipo`:

```
¡Hola, {{1}}! 💅 Te agendaron una cita.

🙋‍♀️ Clienta: *{{2}}*
💅 Servicio: *{{3}}*
📅 {{4}}
⏰ {{5}}
```

`cita_cancelada_equipo`:

```
Hola, {{1}}: se canceló una cita y esa hora te queda libre.

🙋‍♀️ Clienta: *{{2}}*
💅 Servicio: *{{3}}*
📅 {{4}}
⏰ {{5}}
```

`cita_movida_equipo`:

```
Hola, {{1}}: te movieron una cita.

🙋‍♀️ Clienta: *{{2}}*
💅 Servicio: *{{3}}*

❌ Antes: {{4}}
✅ Ahora: *{{5}}*
```

Acá `{{4}}` y `{{5}}` llevan el día y la hora juntos ("Jueves 17 de
septiembre a las 3:00 pm"): lo que ella compara es un momento contra otro,
no cuatro datos sueltos.

En las dos primeras: `{{1}}` profesional · `{{2}}` clienta · `{{3}}` servicio ·
`{{4}}` fecha · `{{5}}` hora. Sin botones: quien atiende abre la agenda.

Todo el detalle en [avisos-al-equipo.md](avisos-al-equipo.md).

**Están apagados por defecto.** Se encienden por negocio (Superadmin → el
negocio → Configuración de agenda → «Avisarle por WhatsApp al equipo») y cada
persona necesita su WhatsApp cargado en su ficha del equipo. Sin número no se
le avisa, y no es una falla: muchas manicuristas no tienen ni cuenta.

Una cita de manos y pies con dos manicuristas manda **dos avisos**, y cada
uno nombra solo el servicio de quien lo recibe.

**Al mover una cita** se avisa según quién gana y quién pierde: a la misma
persona a otra hora le llega `cita_movida_equipo`; si la cita cambia de
manicurista, la que la pierde recibe la de cancelación **con su hora vieja**
--que es el espacio que recupera-- y la que la recibe, la de cita nueva.

### Las difusiones son aparte

Las campañas usan **la plantilla que elija el negocio**, no una nuestra. Si en
ManyChat hay difusiones que quieres conservar, cada una necesita su propia
plantilla aprobada en esta WABA. Esas se eligen desde el panel al crear la
difusión; no van en el código.

## Lo que NO hay que hacer (y ManyChat obligaba)

En ManyChat el contacto tiene que existir allá para poderle escribir: uno busca
a la clienta y, si no está, la plataforma la crea sólo para poder mandarle el
mensaje. Acá **no existe ese paso**: la Cloud API manda a un número de
teléfono, no a un contacto. El hilo en la bandeja se crea solo cuando hace
falta. Nadie tiene que "registrar" a la clienta antes de escribirle.
