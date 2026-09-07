<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
| Requieren que el servidor tenga el `schedule:run` de Laravel en su cron.
| Sin eso esto no corre solo -- y el sintoma es silencioso: nadie recibe
| recordatorios y nadie se entera. Ver docs/recordatorios.md.
*/

/*
 * Recordatorios de cita.
 *
 * Cada 15 minutos y no una vez al dia: la ventana es abierta -- se buscan las
 * citas que arrancan dentro de las proximas N horas -- asi que correr seguido
 * hace que una corrida perdida se recupere sola en la siguiente. Es idempotente
 * por el indice unico de `messages`, no por una bandera.
 *
 * `withoutOverlapping` porque una corrida lenta con muchos negocios podria
 * pisarse con la siguiente. No romperia nada -- el indice unico lo impide --
 * pero serian dos procesos peleandose por la misma tabla sin ganar nada.
 */
Schedule::command('recordatorios:preparar')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Difusiones programadas.
 *
 * Cada cinco minutos, no cada quince: que una promocion salga cuatro minutos
 * tarde no importa; que salga una hora tarde la convierte en otra cosa. La
 * ventana es abierta hacia atras, asi que una corrida perdida se recupera
 * sola.
 *
 * Es idempotente por el indice unico (broadcast_id, client_id) de la tabla
 * de mensajes: dos corridas no mandan dos veces.
 */
Schedule::command('difusiones:enviar')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Historias de Instagram programadas.
 *
 * La API de Meta no programa: publicar es una llamada en el momento, y
 * alguien tiene que hacerla. Misma cadencia que las difusiones.
 */
Schedule::command('historias:publicar')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Traer del sistema viejo lo que pasó allá.
 *
 * CADA MEDIA HORA, y no de noche. Mientras los dos sistemas conviven, el local
 * sigue agendando en el viejo -- y una clienta que llama a las diez para venir
 * a las once tiene que estar en la agenda nueva antes de las once. Una
 * sincronización nocturna dejaría a quien mire la app nueva viendo el día de
 * ayer, que es peor que no verla.
 *
 * Media hora y no cinco minutos porque en este local no hay tanto movimiento:
 * unas pocas citas al día. Lo caro es la primera corrida, no las siguientes.
 *
 * `withoutOverlapping` porque media hora puede no alcanzar el día que alguien
 * cargue mucho de golpe. No rompería nada -- `legacy_map` y los
 * índices únicos lo impiden -- pero serían dos procesos peleándose por las
 * mismas tablas sin ganar nada.
 *
 * Solo se programa si hay un negocio configurado Y credenciales de la base
 * vieja. Un negocio sin convivencia no tiene de dónde traer nada, y programar
 * un comando que siempre falla llena los logs de ruido que nadie lee.
 */
if (filled(config('spa.defaults.legacy_sync_business'))
    && filled(config('database.connections.legacy.username'))) {
    Schedule::command('luxury:importar', [
        '--negocio' => config('spa.defaults.legacy_sync_business'),
    ])
        ->everyThirtyMinutes()
        ->withoutOverlapping()
        ->runInBackground();
}
