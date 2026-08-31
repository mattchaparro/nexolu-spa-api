# Lista de espera

> Diseño, no implementación. Todavía no existe nada de esto en el código.

## Qué problema resuelve

Una clienta llama el martes: quiere uñas acrílicas con María el sábado. El
sábado está lleno. Hoy la respuesta es "no hay nada" y esa clienta se va a otro
lado.

Pero el sábado **casi siempre se abre un hueco**: alguien cancela el viernes en
la noche, alguien no llega. Ese hueco hoy se queda vacío. Nadie llama a nadie
porque nadie se acuerda de quién quería entrar.

La lista de espera es sólo eso: **acordarse de quién quería entrar, y avisarle
cuando se abra el espacio.**

No es una función de calendario. Es una función de recuperar plata que ya se
había perdido.

## Lo que NO es

Vale la pena descartarlo explícitamente, porque son las tres formas naturales
de arruinarlo:

1. **No es una cola con turno.** No hay "primero en la fila". Una clienta que
   pidió el sábado a las 10 no sirve para un hueco del martes a las 3, aunque
   haya pedido primero. Lo que empareja es el **hueco con la preferencia**, no
   el orden de llegada.

2. **No es agendar automático.** Cuando se libera un espacio, al sistema le
   parece que la clienta cabe. A la clienta puede no parecerle: quería el
   sábado en la mañana y se liberó el sábado a las 6 de la tarde. Agendarla
   sola produce una inasistencia con cara de cita.

3. **No es una notificación masiva.** Avisarle a las ocho personas que
   esperaban el sábado produce ocho respuestas para un solo cupo, siete
   clientas molestas y una discusión en el mostrador.

## Cómo funcionaría

### 1. Entrar a la lista

Cuando la disponibilidad devuelve vacío —en la página pública o en el
mostrador— aparece la opción: *"¿Te avisamos si se abre un espacio?"*

Se guarda lo mínimo con lo que después se puede emparejar:

| Dato | Por qué |
|---|---|
| Cliente | A quién avisarle. |
| Servicio(s) | Un hueco de 30 min no sirve para acrílicas de 2 horas. |
| Persona preferida (opcional) | "Con María o con nadie" es una preferencia real y frecuente. |
| Sede | Nadie cruza la ciudad por un hueco. |
| Rango de fechas | "Esta semana", "antes del 15". |
| Franjas que le sirven | Mañana / tarde / noche, y qué días. Es lo que evita ofrecerle un martes a las 3 a quien sólo puede sábados. |
| Vence el | Pasada la fecha, deja de estar en la lista sin que nadie la borre. |

Ese "vence el" no es un detalle: una lista de espera sin vencimiento se llena
de gente que ya se hizo las uñas en otro lado, y a los tres meses nadie confía
en lo que dice.

### 2. Detectar el hueco

Se dispara cuando algo **libera** tiempo, no cada minuto:

- Una cita se cancela.
- Se marca una inasistencia.
- Se reagenda a otro día.
- Se amplía un horario o se quita un bloqueo.

En todos esos casos ya pasamos por `BookingService` o por las excepciones de
horario. El disparador es un evento ahí, no un cron que recorre la agenda: un
cron llegaría tarde y haría trabajo inútil el 99% de las veces.

### 3. Emparejar

Con el hueco concreto —persona, sede, desde/hasta— se busca en la lista quién
encaja:

1. Que el servicio **quepa** en el hueco (duración + buffers, con el mismo
   `AvailabilityService` de siempre; no se reimplementa el cálculo).
2. Que la sede coincida.
3. Que la fecha/franja esté entre las que dijo que le servían.
4. Que la persona preferida coincida, **si puso una**.

De las que encajan, se ofrece a **una sola**. El criterio de desempate es
discutible y es una decisión del negocio, no técnica; el default razonable es
quién lleva más tiempo esperando.

### 4. Ofrecer, con reloj

Se le manda un mensaje con el hueco concreto y un enlace que reserva con un
clic. **El cupo le queda apartado un rato** —30 o 60 minutos, configurable— y
si no responde, pasa a la siguiente.

Esto es lo que hace que funcione y lo más fácil de omitir:

- Sin reserva temporal, dos personas aceptan el mismo cupo y una queda mal.
- Sin vencimiento, el cupo se queda congelado esperando a alguien que ya no va
  a contestar, y se pierde igual que si nunca hubiéramos avisado.

Técnicamente esto ya lo sabemos hacer: es una fila en `resource_occupancy` con
un `expires_at`. El índice único que impide la doble reserva sirve igual para
impedir la doble oferta.

### 5. Después

- Acepta → se agenda normal, sale de la lista.
- No responde → se libera y se ofrece a la siguiente.
- Dice que no → sale de la lista para ese hueco, sigue esperando otros.

## Lo que hay que decidir antes de construirlo

Tres decisiones que son del negocio, no del código, y que cambian el diseño:

1. **¿Cuánto dura la reserva temporal?** Media hora es agresivo pero recupera
   el cupo; dos horas es cómodo pero desperdicia la mitad de las oportunidades
   de un sábado.

2. **¿Se le avisa a una o a varias a la vez?** Una es justo y lento. Tres a la
   vez llena el cupo más rápido y deja dos personas molestas. Yo empezaría con
   una.

3. **¿Cuenta para el abono?** Si el negocio pide abono para separar, ¿la
   clienta de la lista de espera también lo paga? Pedirlo con 40 minutos de
   plazo es fricción sobre fricción.

## Por qué no lo construí todavía

Depende de algo que aún no existe: **`nexolu-comms-api` nunca ha enviado un
mensaje real**. Una lista de espera que no puede avisar no es una lista de
espera, es una tabla.

El orden correcto es: mensajería que funcione → recordatorios automáticos →
lista de espera. Los recordatorios son el caso más simple del mismo canal y
sirven para probarlo con algo que no se rompe si llega tarde.
