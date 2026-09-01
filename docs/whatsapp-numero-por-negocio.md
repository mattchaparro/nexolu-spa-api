# ¿Un número de WhatsApp para todos, o uno por negocio?

> Decisión de arquitectura. Todavía no hay nada implementado: `nexolu-comms-api`
> nunca ha enviado un mensaje real.

## La respuesta corta

**Uno por negocio.** Y no es una preferencia técnica: un número compartido rompe
en producción de formas que no se pueden arreglar después.

Pero con un matiz importante: **con un sandbox de Nexolú para probar**, porque
exigirle a un spa que consiga la aprobación de Meta antes de poder ver si el
producto le sirve es perder al cliente en el onboarding.

## Por qué no un número general

Cinco razones, de la más barata de ignorar a la que hunde el producto.

### 1. La clienta no reconoce el número

Le llega "Tu cita del sábado a las 10" desde un número que no es el de su spa.
En el mejor caso lo ignora. En el peor lo reporta como spam — y el reporte no
cae sobre ese spa, cae sobre el número. Sobre TODOS los spas.

### 2. La calidad del número es compartida

Meta le pone a cada número un *quality rating*. Si baja, primero recorta cuántos
mensajes puede iniciar por día y después lo bloquea. Con un número compartido,
**un negocio que mande de más deja sin recordatorios a todos los demás**, y no
hay forma de aislar al culpable a tiempo.

Es el mismo riesgo que ya nos mordió en el POS con `store-front`: una operación
que parecía acotada tocando algo compartido.

### 3. Las plantillas se aprueban por WABA

Cada mensaje iniciado por el negocio necesita una plantilla aprobada por Meta.
Con un número compartido, o todos usan las mismas plantillas genéricas —adiós a
"Hola {cliente}, soy Aleja de Luxury Nails"— o se acumulan cientos de plantillas
en una sola cuenta, que es exactamente el patrón que Meta penaliza.

### 4. El consentimiento es hacia el negocio

La clienta le dio su número **a su spa**, no a Nexolú. En Colombia la Ley 1581
(habeas data) hace responsable del tratamiento a quien recoge el dato. Mandarle
mensajes desde una cuenta de Nexolú nos convierte en responsables de un dato que
no es nuestro, y le quita al negocio la capacidad de responder por lo suyo.

### 5. El costo es por conversación

WhatsApp cobra por conversación iniciada. Con número propio, cada negocio paga
lo suyo y se puede facturar por plan. Con uno compartido, la factura llega junta
y hay que repartirla a mano.

## Lo que sí cuesta un número por negocio

Ser honestos con el costo, porque es real: **el onboarding**. Un negocio con
número propio necesita Meta Business Manager, verificación del negocio, un
número que no esté ya en WhatsApp normal, y aprobar plantillas.

Un spa de barrio no hace eso solo.

La salida es el **Embedded Signup** de Meta: Nexolú se registra como Tech
Provider y el negocio conecta su propio WABA desde nuestro panel, en un flujo de
unos clics dentro de un popup de Meta. Las credenciales quedan asociadas a
nuestra app pero el número, la marca y la responsabilidad son suyos.

## La arquitectura que esto implica

Credenciales **por negocio**, no en `.env`:

| Dato | Dónde |
|---|---|
| `phone_number_id` | fila por negocio |
| `waba_id` | fila por negocio |
| token de acceso | fila por negocio, cifrado |
| plantillas aprobadas | fila por negocio |

El contrato `MessagingChannel` ya está preparado para esto: no menciona
credenciales por ningún lado. Lo que falta es que la implementación concreta
resuelva las del negocio en vez de leerlas de configuración global.

## Los tres modos, y qué hace cada uno

| Modo | Qué es | Para qué sirve |
|---|---|---|
| **Manual** | No se envía nada. Los enlaces se copian de la ficha del cliente | Probar HOY, sin Meta |
| **Sandbox** | El número de pruebas de Nexolú, sólo a números en lista blanca | Demos, y el primer día de un negocio nuevo |
| **Propio** | Su WABA, conectado por Embedded Signup | Producción |

**Hoy estamos en manual, y funciona.** La ficha de cada cliente trae sus enlaces
personales —"mis citas" y "reservar con sus datos"— con botón de copiar y uno
que abre WhatsApp con el mensaje ya escrito. Quien atiende lo manda desde su
propio teléfono, que es exactamente lo que hace hoy con todo lo demás.

Eso no es un parche temporal: es el modo en que un spa va a operar sus primeras
semanas de todas formas, y hay negocios que no van a querer salir de ahí nunca.

## Lo que NO hay que hacer

**Consultar citas por teléfono desde una URL pública.** Ya está descartado y
escrito en `ClientPortalService`: un teléfono no es un secreto, y una pantalla
que los acepte es un directorio de clientas ajenas.

Cuando el canal de WhatsApp exista, resolver por teléfono **sí** va a ser
correcto — pero por otra puerta: quien pregunta será el canal autenticado como
servicio, y el número lo verifica WhatsApp, no el usuario. Es la misma
distinción que ya existe entre `/api/v1/public/*` y `/api/ai/*`.
