<?php

/*
|--------------------------------------------------------------------------
| Defaults de agenda
|--------------------------------------------------------------------------
| Son valores por defecto de plataforma, NO reglas de negocio. Cada negocio
| sobreescribe los suyos en `businesses.scheduling_settings` -- nada de lo
| que hay aca debe leerse directo desde un Service sin pasar antes por la
| configuracion del negocio.
|
| Blue Souls tenia estos numeros incrustados en el codigo (bloques de 120
| minutos, 3 horas de anticipacion, multa de 10000). Ese es exactamente el
| error que este archivo existe para no repetir.
*/

return [

    /*
    | Donde vive la pagina publica de reservas (sin barra final). El bot se
    | lo manda a quien prefiere agendar sola, y el menu de WhatsApp lo abre
    | con un boton. Por defecto la agenda de produccion.
    */
    'public_booking_url' => env('PUBLIC_BOOKING_URL', 'https://agenda.nexolu.co'),

    /*
    | El Flow de WhatsApp que confirma la cita como formulario nativo
    | pre-cargado (docs/whatsapp-flows/). Vacio = se sigue confirmando con
    | botones; se llena al publicar el Flow en el WhatsApp Manager.
    */
    'whatsapp_booking_flow_id' => env('WHATSAPP_BOOKING_FLOW_ID'),

    /*
    | El Flow con el calendario nativo (docs/whatsapp-flows/elegir-fecha.json)
    | que se abre al tocar «Otro día». Vacio = la lista de los 7 dias
    | siguientes, como antes.
    */
    'whatsapp_date_flow_id' => env('WHATSAPP_DATE_FLOW_ID'),

    /*
    | El Flow de la encuesta (docs/whatsapp-flows/encuesta.json) que se abre
    | al tocar «Calificar servicio». Vacio = el enlace a la encuesta web.
    */
    'whatsapp_survey_flow_id' => env('WHATSAPP_SURVEY_FLOW_ID'),

    'defaults' => [
        // Granularidad de la rejilla de disponibilidad, en minutos.
        'slot_granularity_min' => 15,

        // Anticipacion minima para que un cliente reserve por su cuenta.
        'min_booking_notice_min' => 60,

        // Anticipacion minima para cancelar sin penalizacion.
        'min_cancellation_notice_min' => 180,

        /*
         * Cuantas horas antes se le recuerda la cita al cliente.
         *
         * 24 y no 2: el recordatorio sirve para que quien no va a poder avise
         * A TIEMPO, no para que se acuerde de correr. Dos horas antes ya no
         * alcanza a vender ese hueco otra vez, que es de lo que se trata bajar
         * las inasistencias.
         */
        'reminder_hours_before' => 24,

        // Cuanto hacia adelante se puede reservar.
        'max_booking_horizon_days' => 60,

        /*
         * Cada cuantos dias se retoca un servicio que no diga lo suyo.
         * Veinte dias es lo que dura un semipermanente antes de verse
         * crecido; cada servicio lo ajusta (0 = no se retoca).
         */
        'retouch_days' => 20,

        /*
         * A que hora local sale el recordatorio de retoque. Las 10 de la
         * manana: ya desayuno y todavia puede cuadrar el dia; de noche se
         * lee y se olvida.
         */
        'retouch_reminder_hour' => 10,

        /*
         * Avisarle por WhatsApp a quien atiende cuando le agendan o le
         * cancelan una cita.
         *
         * APAGADO por defecto, y no es timidez: encenderlo solo significaria
         * empezar a escribirle al equipo de cada negocio, a nombre del salon,
         * sin que nadie lo pidiera. Cada negocio lo prende y carga los
         * telefonos de su equipo.
         */
        'notify_team_whatsapp' => false,

        // Penalizacion por inasistencia. 0 = deshabilitada.
        'no_show_penalty_amount' => 0,

        /*
         * Multa por cancelar dentro de `min_cancellation_notice_min`. null =
         * la misma de inasistencia. Cancelar tarde se permite -- libera la
         * silla -- pero queda registrado en la ficha con este valor.
         */
        'late_cancellation_penalty_amount' => null,

        /*
         * Abono para separar la cita. Solo aplica con la bandera
         * `booking_deposit` encendida.
         *
         * El default NO pide nada aunque la bandera se encienda: prender el
         * modulo y que de una empiece a pedirle plata por adelantado a los
         * clientes, con un monto que nadie eligio, es la clase de sorpresa que
         * el negocio descubre por las quejas.
         */
        'deposit_type' => 'none',   // none | percent | fixed
        'deposit_value' => 0,
        'deposit_instructions' => null,

        'timezone' => 'America/Bogota',

        /*
         * Sobre que valor se le paga comision a quien atendio cuando hubo
         * descuento. `charged` = sobre lo cobrado; `list` = sobre el precio de
         * lista, y el descuento lo asume el negocio.
         *
         * MANUAL y COMBO arrancan en `charged`, que es lo que el sistema ya
         * hacia: nadie se despierta con la nomina cambiada por un deploy.
         *
         * FIDELIZACION arranca en `list`: el premio de la tarjeta lo REGALA EL
         * NEGOCIO para que la clienta vuelva, y el trabajo de quien atiende fue
         * exactamente el mismo. Bajarle la comision seria cobrarle a ella una
         * promesa que hizo el local.
         *
         * Este default estuvo en `charged` y se corrigio: es lo que hace el spa
         * de Luxury desde hace anos (blue-souls-app calcula la comision sobre
         * el precio de catalogo cuando hay descuento, y registra la diferencia
         * como gasto "Retencion cliente"), y el sistema nuevo no puede
         * estrenarse recortandole la nomina al equipo que se va a migrar.
         *
         * Cada negocio puede darlo vuelta desde "Pagos al equipo".
         */
        /*
         * Cuanto se calla el agente de WhatsApp cuando alguien del equipo
         * contesta a mano, en minutos.
         *
         * Dos horas: lo que dura una conversacion de mostrador con sus pausas.
         * Mas corto y el agente se mete a mitad de la charla; mas largo y una
         * clienta que escribe esa misma tarde se queda sin respuesta
         * automatica porque alguien contesto una vez en la manana.
         *
         * Se puede soltar antes a mano desde la bandeja.
         */
        /*
         * El negocio que se sincroniza desde el sistema viejo, por slug.
         *
         * Vacio = no hay convivencia y la sincronizacion no se programa. Es lo
         * correcto para cualquier otro negocio de la plataforma: nadie mas
         * tiene una app vieja de la cual traer datos.
         */
        'legacy_sync_business' => env('LEGACY_SYNC_BUSINESS'),

        'whatsapp_agent_pause_min' => 120,

        /*
         * Cuanto se espera antes de contestar, por si viene otro mensaje.
         *
         * La gente escribe por WhatsApp como habla: "requiero una cita para
         * hombre" / "pero a las 10" / "no se si se pueda con Angy". Son
         * cuatro mensajes y UNA sola idea. Contestar cada pedazo produce
         * cuatro respuestas, todas con informacion incompleta, y ademas
         * gasta cuatro veces el modelo.
         *
         * Ocho segundos: suficiente para alcanzar al que sigue escribiendo,
         * poco para que no parezca que nadie contesta. El "escribiendo..."
         * sale de inmediato, asi que el silencio no se siente.
         */
        'whatsapp_agent_debounce_seconds' => 8,

        'commission_base_manual' => 'charged',
        'commission_base_package' => 'charged',
        'commission_base_loyalty' => 'list',

        /*
         * La campana SI arranca en `list`: es el unico origen que el negocio
         * decide por su cuenta, para traer gente que de otro modo no habria
         * venido. Bajarle la comision a quien atiende por una promocion que
         * nadie le consulto es cobrarle a ella la publicidad del local.
         */
        'commission_base_campaign' => 'list',
    ],

];
