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
