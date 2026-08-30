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
    ],

];
