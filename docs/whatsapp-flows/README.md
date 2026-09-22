# WhatsApp Flows del agente

El formulario nativo que confirma la cita: la IA reúne los datos
conversando y la clienta **revisa y confirma** en una pantalla — servicio,
fecha (selector nativo), hora y nombre, todo pre-cargado y editable.

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
