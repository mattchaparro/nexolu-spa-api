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

        // Penalizacion por inasistencia. 0 = deshabilitada.
        'no_show_penalty_amount' => 0,

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
