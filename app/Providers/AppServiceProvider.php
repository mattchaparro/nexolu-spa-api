<?php

namespace App\Providers;

use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\Messaging\Contracts\MessagingCostReporter;
use App\Services\WhatsApp\LoggingMessagingChannel;
use App\Services\WhatsApp\NexoluCommsChannel;
use App\Services\WhatsApp\NexoluCommsCostReporter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A diferencia del POS -- que arrastra un driver historico contra la
        // Graph API de Meta -- este producto nace hablando solo con Nexolu
        // Communications. No hay segundo driver a proposito: agregar uno
        // seria reintroducir la deuda que el POS todavia esta pagando.
        //
        // LoggingMessagingChannel envuelve el canal para que todo envio quede
        // registrado sin que el codigo que lo dispara tenga que acordarse.
        $this->app->bind(MessagingChannel::class, fn () => new LoggingMessagingChannel(
            $this->app->make(NexoluCommsChannel::class)
        ));

        $this->app->bind(MessagingCostReporter::class, fn () => $this->app->make(NexoluCommsCostReporter::class));
    }

    public function boot(): void
    {
        /*
         * Limitadores con nombre, y no `throttle:5,1` suelto.
         *
         * Dos `throttle:` anidados sobre la misma ruta comparten cubeta: sin
         * nombre, la llave es sha1(dominio|ip) para los dos, asi que cada
         * peticion incrementa el mismo contador DOS veces y el limite de 5 se
         * agota en la tercera. Darle nombre a cada uno les da su propia llave.
         */
        RateLimiter::for('pagina-publica', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Mas apretado: mirar la pagina es gratis, llenar la agenda no.
        RateLimiter::for('reserva-publica', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Las respuestas van sin envoltura "data", igual que el POS -- el
        // front nuevo asume esa forma.
        JsonResource::withoutWrapping();

        // Las fechas legibles (recordatorios, confirmaciones) salen en
        // espanol sin importar APP_LOCALE, que se queda en "en" para los
        // mensajes de validacion del framework.
        Carbon::setLocale('es');
    }
}
