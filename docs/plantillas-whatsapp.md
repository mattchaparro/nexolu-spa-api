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

## Las tres, y cómo crearlas

En el WhatsApp Manager, idioma **es**. El nombre y **el orden de las
variables** tienen que ser exactos: Meta no recibe nombres, recibe una lista
posicional, y un parámetro en el puesto equivocado manda "tu cita en miércoles
3 a las Luxury Nails" sin que nada falle.

El **nombre del negocio va adentro** de las tres a propósito: con el número
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

### 2. `recordatorio_cita` — categoría *utility*

```
Hola {{1}}, te recordamos tu cita en {{2}} el {{3}} a las {{4}}.
```

`{{1}}` cliente · `{{2}}` negocio · `{{3}}` fecha · `{{4}}` hora.

Dentro de la ventana sale el texto del negocio, que **sí** lleva el enlace de
«mis citas» — y ahí está la diferencia entre un recordatorio que sirve y uno
que no: si la persona no va a poder, tiene que poder moverla en ese momento.

**Pendiente de decidir:** fuera de la ventana —que es el caso normal de un
recordatorio— hoy llega sin salida: ni enlace ni botones. Los que tendrían
sentido son `Confirmo que voy`, `Reagendar` y `Cancelar cita`; «Reagendar» ya
tiene su camino en el bot, los otros dos hay que construirlos antes de poner
el botón. Un botón que no hace nada es peor que no tenerlo.

### 3. `retoque_recordatorio` — categoría *marketing*

Ver [retoques.md](retoques.md): encabezado, cuerpo, pie y los tres botones
(`Agendar retoque`, `Empezar de cero`, `Darme de baja`), cuyos textos son la
interfaz y no se pueden cambiar en Meta sin cambiarlos en el código.

## Lo que NO hay que hacer (y ManyChat obligaba)

En ManyChat el contacto tiene que existir allá para poderle escribir: uno busca
a la clienta y, si no está, la plataforma la crea sólo para poder mandarle el
mensaje. Acá **no existe ese paso**: la Cloud API manda a un número de
teléfono, no a un contacto. El hilo en la bandeja se crea solo cuando hace
falta. Nadie tiene que "registrar" a la clienta antes de escribirle.
