# WhatsApp Flows del agente

Dos formularios:

| Archivo | Para qué | Variable |
|---|---|---|
| [confirmar-cita.json](confirmar-cita.json) | Confirmar la cita: hora entre las libres, nombre | `WHATSAPP_BOOKING_FLOW_ID` |
| [elegir-fecha.json](elegir-fecha.json) | Calendario nativo al tocar «Otro día» | `WHATSAPP_DATE_FLOW_ID` |

## Elegir fecha (el calendario)

Al tocar «Otro día» se abre el selector de fecha nativo de WhatsApp: desde
hoy hasta `max_booking_horizon_days`, con los días en que nadie del equipo
trabaja bloqueados (sin horario ese día de la semana). La fecha vuelve como
`{"pedido": "fecha", "fecha": "YYYY-MM-DD"}` y el bot manda las horas de ese
día (con mudanza incluida, si venía de «Reagendar»). Sin la variable, «Otro
día» sigue mandando la lista de los 7 días siguientes.

Publicarlo: WhatsApp Manager → Flows → *Create Flow* → pegar
`elegir-fecha.json` → *Preview* → **Publish** → copiar el Flow ID →
`WHATSAPP_DATE_FLOW_ID=<id>` en el `.env` del spa y redeploy.

---

El formulario nativo que confirma la cita: la IA reúne los datos
conversando y la clienta **revisa y confirma** en una pantalla.

## Qué puede editar la clienta (y por qué solo eso)

En un Flow estático los datos se cargan AL ENVIARLO y nada se recalcula
después. Por eso el formulario solo deja editar lo que no afecta la
agenda:

- **Servicio y fecha: solo lectura** (el encabezado). Cambiarlos exigiría
  recalcular horas — eso es la v2, abajo.
- **Hora: editable, pero solo entre las horas LIBRES de ese día**, que
  viajan como data al momento del envío. Si justo se ocupa, la guarda de
  `crear_cita` lo ataja y se le avisa.
- **Nombre y para quién: editables** — el remedio para los perfiles
  raros de WhatsApp.
- ¿Otro día u otro servicio? Cierra el formulario y lo escribe: eso es
  conversación.

## v2 (siguiente iteración): el formulario dinámico

Servicio → manicurista → fecha → horas reales en cada paso, con un Flow
de `data_exchange`: Meta cifra cada interacción (RSA+AES, llave pública
registrada en el número) y un endpoint nuestro responde. El endpoint va
en **Connect** (dueño del plumbing de Meta), que descifra y reenvía a la
app dueña; el spa ya expone la lectura de disponibilidad para flujos
(la "Solicitud externa" de la fase 06). Al construirlo, esto se vuelve
feature de producto Connect para cualquier vertical.

## Estado

- **Recepción: lista y desplegada.** El `nfm_reply` (lo que la clienta
  envía al completar el formulario) llega por el webhook de Connect, el
  spa lo reconoce por la marca `"pedido": "cita"` y lo convierte en
  `crear_cita` con todas las guardas de siempre
  (`App\Ai\BookingForm` + `ProcessBookingFormJob`).
- **Envío: pendiente de publicar el Flow.** `NexoluCommsChannel::sendFlow`
  ya sabe mandarlo pre-cargado; falta el `flow_id`.

## Cómo publicarlo (pasos de Alejandro, una vez)

1. WhatsApp Manager → cuenta de Luxury Nails → **Flows** → *Create Flow*
   → categoría "Appointment booking" → editor JSON → pegar
   [confirmar-cita.json](confirmar-cita.json) → guardar.
2. Probar con *Preview* (el ejemplo `__example__` llena la pantalla).
3. **Publish**. Copiar el **Flow ID**.
4. En el `.env` de producción del spa: `WHATSAPP_BOOKING_FLOW_ID=<id>`
   y redeploy.

Sin el ID configurado, el agente sigue confirmando con botones (Sí,
agendar / Otra hora): nada se rompe por publicar después.

## Contrato del payload (no cambiarlo sin tocar `BookingForm`)

```json
{ "pedido": "cita", "servicio": "...", "fecha": "<YYYY-MM-DD o epoch ms>",
  "hora": "HH:MM", "nombre": "", "para_quien": "" }
```

- El DatePicker nativo entrega **epoch en milisegundos**; `BookingForm`
  lo traduce a fecha del negocio (América/Bogotá).
- `hora` viaja como `hora_24` (`HH:MM`) en el `id` del dropdown; el
  `title` es la hora legible ("3:00 pm").
- Las horas del dropdown son **las ofrecidas en la conversación** (data
  estática del envío): cambiar la fecha en el formulario no recalcula
  horas — eso pediría un data-exchange endpoint cifrado, fase 2.
