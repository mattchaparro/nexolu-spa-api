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
         * Cuantos minutos despues de que TERMINA el servicio se le avisa a
         * quien atendio que lo registre.
         *
         * 0 = apagado, y es el default: prender solo un aviso al equipo sin
         * que nadie lo pida es mandarle WhatsApp a las empleadas de un negocio
         * a su nombre. Lo enciende quien lo quiera.
         *
         * El ancla es `service_ends_at` del ultimo item, que es la hora real a
         * la que la clienta se levanta de la silla -- no `ends_at`, que
         * incluye el buffer de limpieza y avisaria tarde.
         */
        'service_done_reminder_min' => 0,

        /*
         * Hasta cuando vale la pena avisar. Un servicio que termino hace seis
         * horas ya se cobro, se olvido, o el dia se acabo: recordarlo a las
         * nueve de la noche solo entrena a ignorar el aviso.
         */
        'service_done_reminder_max_age_min' => 240,

        /*
         * Si al cerrar el servicio se pide la foto del trabajo.
         *
         * `none` = no se pide. `ask` = se pide, pero NO SE EXIGE: bloquear el
         * cobro por una foto que falta termina en dos sitios, una foto
         * cualquiera subida para poder cobrar, o una caja que no cierra. Una
         * barberia lo deja en `none` -- ahi no se acostumbra fotografiar la
         * cara de nadie, que es distinto de fotografiar unas manos.
         */
        'service_photo_policy' => 'none',   // none | ask

        /*
         * Si al cobrar se pide el comprobante de pago.
         *
         * `non_cash` es el interesante: se pide solo cuando el medio de pago
         * NO cuenta como efectivo (`payment_methods.counts_as_cash`). El
         * efectivo se cuenta en el cajon al cerrar el dia; una transferencia
         * no se puede contar, y sin comprobante el cierre cuadra contra lo que
         * alguien dijo.
         */
        'payment_proof_policy' => 'none',   // none | non_cash | always

        /*
         * Sobre que valor se le paga comision a quien atendio cuando hubo
         * descuento. `charged` = sobre lo cobrado; `list` = sobre el precio de
         * lista, y el descuento lo asume el negocio.
         *
         * Los tres arrancan en `charged`, que es lo que el sistema ya hacia:
         * nadie se despierta con la nomina cambiada por un deploy.
         *
         * FIDELIZACION en `charged` por decision del negocio: el premio es una
         * atencion al cliente por su fidelidad, y de esa fidelidad vive
         * tambien quien lo atiende -- una clienta que vuelve es trabajo suyo.
         * Es distinto de una campana de temporada (mes de la madre), que la
         * decide el negocio para traer gente nueva y por eso la absorbe el
         * negocio; cuando exista ese modulo, su default sera `list`.
         *
         * Cada negocio puede darlo vuelta desde "Pagos al equipo".
         */
        'commission_base_manual' => 'charged',
        'commission_base_package' => 'charged',
        'commission_base_loyalty' => 'charged',

        /*
         * La campana SI arranca en `list`: es el unico origen que el negocio
         * decide por su cuenta, para traer gente que de otro modo no habria
         * venido. Bajarle la comision a quien atiende por una promocion que
         * nadie le consulto es cobrarle a ella la publicidad del local.
         */
        'commission_base_campaign' => 'list',
    ],

    /*
    |--------------------------------------------------------------------------
    | Publicaciones en redes
    |--------------------------------------------------------------------------
    | Los numeros con que el planificador decide QUE proponer. Son de
    | plataforma y no del negocio a proposito: el control real que tiene el
    | negocio no es cuantas propuestas recibe -- son propuestas, no salen
    | solas -- sino cuales aprueba. Cuando algun negocio pida afinar esto se
    | agrega una columna, no antes.
    */
    'social' => [

        /*
         * Cuantas propuestas sin revisar se toleran antes de dejar de
         * proponer.
         *
         * Una bandeja con cuarenta ideas que nadie miro no se arregla
         * agregando la cuarenta y uno: se deja de abrir. El planificador se
         * calla y espera a que alguien vacie lo que hay.
         */
        'max_open_drafts' => 12,

        // Una propuesta automatica que nadie toco en este tiempo se descarta
        // sola. La de una persona no se descarta nunca.
        'draft_stale_days' => 10,

        // Que tan reciente tiene que ser una foto para valer como noticia.
        // Un trabajo de hace un mes ya no es "miren lo de ayer".
        'work_photo_days' => 14,
        'work_photos_per_run' => 2,

        /*
         * El hueco. Se mira desde manana -- hoy ya no da tiempo de aprobar,
         * publicar y que alguien reaccione -- hasta el horizonte.
         */
        'gap_horizon_days' => 5,

        // Cuantos minutos libres tiene que sumar un dia para que valga la
        // pena anunciarlo. Menos de tres horas es un dia normal, no un hueco.
        'gap_min_free_min' => 180,
        'gaps_per_run' => 2,

        // Un servicio que no se vende hace tanto merece que le recuerden al
        // barrio que existe.
        'service_quiet_weeks' => 6,
    ],

];
