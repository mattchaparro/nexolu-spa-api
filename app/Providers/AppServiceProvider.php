<?php

namespace App\Providers;

use App\Services\Messaging\Contracts\MessagingChannel;
use App\Services\Messaging\Contracts\MessagingCostReporter;
use App\Services\WhatsApp\LoggingMessagingChannel;
use App\Services\WhatsApp\NexoluCommsChannel;
use App\Services\WhatsApp\NexoluCommsCostReporter;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
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
        // Las respuestas van sin envoltura "data", igual que el POS -- el
        // front nuevo asume esa forma.
        JsonResource::withoutWrapping();

        // Las fechas legibles (recordatorios, confirmaciones) salen en
        // espanol sin importar APP_LOCALE, que se queda en "en" para los
        // mensajes de validacion del framework.
        Carbon::setLocale('es');
    }
}
