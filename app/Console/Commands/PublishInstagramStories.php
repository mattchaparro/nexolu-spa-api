<?php

namespace App\Console\Commands;

use App\Models\InstagramStory;
use App\Services\Instagram\StoryPublisher;
use Illuminate\Console\Command;

/**
 * Publica las historias cuya hora llego.
 *
 * Existe porque la API de Instagram no programa: publicar es una llamada en
 * el momento, y alguien tiene que hacerla. Cada cinco minutos, con ventana
 * abierta hacia atras para que una corrida perdida se recupere sola.
 */
class PublishInstagramStories extends Command
{
    protected $signature = 'historias:publicar';

    protected $description = 'Publica en Instagram las historias programadas cuya hora ya pasó';

    public function handle(StoryPublisher $publisher): int
    {
        $pendientes = InstagramStory::withoutGlobalScope('business')
            ->where('status', InstagramStory::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($pendientes->isEmpty()) {
            $this->info('Nada programado por ahora.');

            return self::SUCCESS;
        }

        foreach ($pendientes as $historia) {
            $ok = $publisher->publish($historia);

            $this->line($ok
                ? "Historia #{$historia->id}: publicada."
                : "Historia #{$historia->id}: {$historia->fresh()->error}");
        }

        return self::SUCCESS;
    }
}
