# El agente de WhatsApp

Cómo está armado, por qué, y cómo se levanta en local.

## El recorrido de un mensaje

```
clienta → WhatsApp → Meta
                       ↓ webhook
              nexolu-comms-api        (verifica que sea Meta, reenvía firmado)
                       ↓
   spa-api  POST /api/webhooks/nexolu-comms/whatsapp
                       ↓
              nexolu-ia-core  POST /v1/chat      (decide qué hacer)
                       ↓ tool call
   spa-api  POST /api/ai/tools/invoke            (lo ejecuta de verdad)
                       ↓
              respuesta → outbox → comms → Meta → clienta
```

**El Core decide, el Spa ejecuta.** Las garantías de agendamiento —el índice
único que impide el doble booking, los límites por sede, el preaviso mínimo—
viven en `BookingService`. Si el agente agendara por su cuenta sería un segundo
camino con reglas distintas, que es justo el problema del que este producto
viene huyendo.

## De quién es cada conversación

La pregunta más delicada. Se responde en tres pasos, del más confiable al menos:

1. **El `phone_number_id` que recibió.** Si el negocio tiene WhatsApp propio, ya
   está identificado. Nada de lo que se escriba puede contradecirlo.
2. **La conversación abierta** de ese teléfono.
3. **El código del wa.link** (`NX-XXXX`) en el texto.

El código va de último **porque es lo único que viaja dentro de texto que la
clienta puede editar**. Si fuera primero, escribir el código de otro spa movería
la conversación de negocio a mitad de charla.

### Los dos modos

| Modo | Cómo se identifica | Para quién |
|---|---|---|
| Número propio | `businesses.whatsapp_phone_number_id` | Luxury Nails en producción |
| Número compartido | `businesses.whatsapp_code` en el wa.link | Negocios que compran la app |

Ninguna de las dos columnas es `fillable`: de quién es un número de WhatsApp no
puede quedar al alcance de un formulario.

El wa.link que publica un negocio en su respuesta automática:

```
https://wa.me/<numero-nexolu>?text=Hola%2C%20quiero%20agendar%20en%20<Negocio>%20%28NX-XXXX%29
```

## Texto libre contra plantilla

WhatsApp **solo entrega texto libre dentro de las 24h siguientes a que la
clienta escribió**. Eso divide los mensajes en dos:

| Qué | Cómo sale | Por qué |
|---|---|---|
| Respuesta del agente | texto libre | contesta dentro de una conversación que ella abrió |
| Recordatorio, cupo liberado, encuesta | **plantilla aprobada** | los inicia el negocio, mucho después |

Por eso `messages` guarda las dos cosas: `body` (lo que una persona manda a mano
en modo manual, y lo que se lee en la bandeja) y `template_name` +
`template_params` (cómo sale solo). De *"Hola Carolina, tu cita del jueves..."*
no se sacan de vuelta las variables sin adivinar.

Las plantillas se crean por API y las aprueba Meta en minutos:

```bash
POST https://graph.facebook.com/v21.0/{waba_id}/message_templates
```

Dos reglas que Meta no perdona: **una variable no puede quedar al principio ni
al final**, y toda variable necesita un valor de ejemplo.

Con número compartido **no hacen falta plantillas por negocio**: el nombre del
negocio es una variable. Son ~4 plantillas para toda la plataforma.

## Lo que el agente NO puede hacer

- **Enumerar clientes.** El Core declara la herramienta `clientes`; el Spa no la
  implementa a propósito y responde 404. La base de clientas del negocio no sale
  por un chat.
- **Tocar datos de otra persona.** El `cita_id` que propone el modelo se verifica
  contra el negocio *y* contra esa clienta.
- **Agendarle a un tercero.** El nombre que mande el modelo no elige a quién se
  le agenda: eso lo decide el teléfono, que lo verifica WhatsApp.
- **Usar una capacidad nueva sin pensarlo.** `allowsCustomers()` se implementa
  explícitamente en cada una; el olvido produce un 403, no una fuga.

## Levantarlo en local

Cuatro procesos. Puertos: spa-api 8100, ia-core 8001, comms 8002, front 5273.

```bash
# 1. ia-core
cd C:\Nexolu\nexolu-ia-core
./.venv/Scripts/python.exe -m uvicorn nexolu_ia_core.main:app --port 8001

# 2. comms
cd C:\Nexolu\nexolu-comms-api
./.venv/Scripts/python.exe -m uvicorn nexolu_comms_api.main:app --port 8002

# 3. spa-api y front, como siempre
```

Registrar el Spa en ia-core (una vez; devuelve la api key EN CLARO una sola vez):

```bash
curl -X POST http://localhost:8001/v1/admin/apps \
  -H "Authorization: Bearer $NEXOLU_PLATFORM_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"app_id":"spa","name":"Nexolu Spa","base_url":"http://localhost:8100"}'
```

Esa llave va en `IA_CORE_API_KEY` del Spa.

### Sin llave de modelo

`DEFAULT_PROVIDER=null` en ia-core es un proveedor determinista que no sale a la
red: sirve para probar el circuito entero (firma, enrutamiento, conversación,
persistencia, outbox) sin gastar tokens. **No ejecuta herramientas**, así que
para ver al agente agendar de verdad hace falta una llave real
(`OPENROUTER_API_KEY` y `DEFAULT_PROVIDER=openrouter`).

### Simular un mensaje entrante

Sin Meta ni comms, firmando a mano con `COMMS_CORE_WEBHOOK_SECRET`:

```bash
POST http://localhost:8100/api/webhooks/nexolu-comms/whatsapp
X-Nexolu-Timestamp: <epoch>
X-Nexolu-Signature: hmac_sha256("<epoch>.<cuerpo>", $COMMS_CORE_WEBHOOK_SECRET)
```

Con el cuerpo crudo de Meta. Los tres tipos que se atienden son `text`, `button`
(botones de plantilla) e `interactive` (listas y respuestas rápidas).

## Pendiente

- **Prompt por negocio.** Hoy los prompts de ia-core son constantes en Python.
  Para que el agente hable como Luxury Nails y no como un spa genérico, hay que
  agregarlo.
- **Migrar el número de Luxury Nails desde ManyChat.** Es el paso más riesgoso:
  hay que desconectarlo allá antes de registrarlo en Cloud API, y no es
  reversible en un clic. Hacerlo con el agente ya probado contra el número de
  pruebas.
- **Token permanente de System User.** El del panel de Meta dura 24h.
