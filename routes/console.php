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
 * Publicaciones: buscar de que publicar.
 *
 * Una vez al dia y temprano. Temprano porque la noticia mas util que
 * encuentra es el hueco de manana, y para que sirva alguien tiene que
 * aprobarla y publicarla HOY -- una propuesta que aparece a las seis de la
 * tarde llega tarde a su propio dia.
 *
 * Es cara: calcular los huecos de la semana recorre el horario de cada
 * persona del equipo, dia por dia. Por eso corre una vez y no cada quince
 * minutos, y por eso `withoutOverlapping`.
 */
Schedule::command('publicaciones:proponer')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Publicaciones: liberar lo que ya cumplio su hora.
 *
 * Cada quince minutos y barato: una consulta por negocio. Es lo que hace que
 * "programada para el jueves a las 10" signifique algo en pantalla. No
 * publica nada -- ver PostDispatcher.
 */
Schedule::command('publicaciones:despachar')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * "Terminaste, registralo": el aviso a quien atendio.
 *
 * Cada 15 minutos, como los recordatorios y por lo mismo: la ventana es
 * abierta -- se toman los servicios que ya cumplieron su hora -- asi que una
 * corrida perdida se recupera sola en la siguiente. Es idempotente por el
 * indice unico de `messages`, no por una bandera.
 *
 * Viene apagado para todo negocio (`service_done_reminder_min` = 0), asi que
 * esta entrada no manda nada hasta que alguien lo encienda.
 */
Schedule::command('servicios:recordar')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Los tokens de Instagram, antes de que caduquen.
 *
 * Diario y no mensual: se renuevan con siete dias de anticipacion, asi que una
 * corrida perdida tiene seis reintentos antes de que importe. Mensual daria un
 * solo intento -- y un token vencido no avisa: las publicaciones dejan de
 * salir en silencio y el negocio se entera semanas despues.
 */
Schedule::command('redes:renovar-tokens')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();
