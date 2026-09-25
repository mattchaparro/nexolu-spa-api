<?php

namespace App\Console\Commands;

use App\Ai\AiCaller;
use App\Ai\EnvioDirecto;
use App\Ai\ServiciosPendientes;
use App\Ai\UltimoPedido;
use App\Models\Appointment;
use App\Models\Message;
use App\Models\WhatsappConversation;
use App\Support\NombreDePila;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * El "carrito abandonado" de la agenda: quien se quedó a mitad de agendar.
 *
 * Claus pasó tres páginas del catálogo buscando su servicio y se fue sin
 * decir nada. En el mostrador alguien le habría preguntado «¿te ayudo?».
 * Esto hace eso: a los 15 minutos sin respuesta, con el agendamiento a
 * medias, le llega UNA pregunta que suena a persona, según dónde quedó
 * (el catálogo, las horas, el día...). Lo que conteste lo atiende el bot
 * como siempre -- con lo que ya había elegido guardado -- y si no puede,
 * `hablar_con_persona` la pasa al equipo.
 *
 * Una vez por mensaje suyo y a lo sumo cada seis horas por número: la
 * pregunta ayuda la primera vez; la tercera es acoso.
 */
class NudgeAbandonedBookings extends Command
{
    protected $signature = 'bot:nudge-abandoned
                            {--dry-run : Muestra a quién le escribiría, sin mandar nada}';

    protected $description = 'Le pregunta «¿te ayudo?» a quien dejó un agendamiento a medias hace 15 minutos';

    /** Minutos sin responder para preguntar, y hasta cuándo vale la pena. */
    public const IDLE_MINUTES = 15;

    private const GIVE_UP_MINUTES = 60;

    /** Horas en que se le puede escribir a alguien (hora del negocio). */
    private const FROM_HOUR = 7;

    private const UNTIL_HOUR = 21;

    public function handle(EnvioDirecto $envio): int
    {
        $sent = 0;

        $conversations = WhatsappConversation::withoutGlobalScope('business')
            ->with('business', 'client')
            ->whereBetween('last_inbound_at', [
                now()->subMinutes(self::GIVE_UP_MINUTES),
                now()->subMinutes(self::IDLE_MINUTES),
            ])
            ->get();

        foreach ($conversations as $conversation) {
            $business = $conversation->business;

            if ($business === null || ! $business->is_active || $conversation->agentIsPaused()) {
                continue;
            }

            $hour = CarbonImmutable::now($business->businessTimezone())->hour;
            if ($hour < self::FROM_HOUR || $hour >= self::UNTIL_HOUR) {
                continue;
            }

            // Lo último lo dijo el bot: ella es la que no contestó. Si lo
            // último es suyo, el bot le debe una respuesta -- eso es otro
            // problema, y una pregunta encima lo empeora.
            $last = Message::withoutGlobalScopes()
                ->where('conversation_id', $conversation->id)
                ->latest('id')
                ->first();

            if ($last === null || $last->direction !== Message::DIRECTION_OUT) {
                continue;
            }

            $phone = (string) $conversation->phone;
            $stage = self::stage(UltimoPedido::ver($phone), ServiciosPendientes::ver($phone));

            if ($stage === null || $this->bookedRecently($conversation)) {
                continue;
            }

            $text = self::message($stage, NombreDePila::deSaludo($conversation->client?->name));

            if ($this->option('dry-run')) {
                $this->line("  {$phone} · {$stage} · {$text}");
                $sent++;

                continue;
            }

            $once = 'abandoned_nudge:'.$conversation->id.':'.$conversation->last_inbound_at->timestamp;
            if (! Cache::add($once, true, now()->addDay())) {
                continue;
            }
            if (! Cache::add('abandoned_nudge_phone:'.$phone, true, now()->addHours(6))) {
                continue;
            }

            $caller = AiCaller::customer($business, $phone, $conversation->client, 'whatsapp');

            if ($envio->texto($caller, $text)) {
                $sent++;
            }
        }

        $this->info("Agendamientos a medias: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * Dónde quedó el agendamiento, o null si no hay uno a medias.
     *
     * @param  array<string, mixed>  $pedido
     * @param  list<string>  $pendingServices
     */
    public static function stage(array $pedido, array $pendingServices): ?string
    {
        return match (true) {
            isset($pedido['confirmar']) => 'confirm',
            ! empty($pedido['horas']) => 'hours',
            ! empty($pedido['eligiendo_empleado']) => 'person',
            ! empty($pedido['eligiendo_fecha']) || ! empty($pedido['sin_horas']) => 'day',
            $pendingServices !== [] || (! empty($pedido['opciones']) && empty($pedido['servicios'])) => 'catalog',
            ($pedido['menu'] ?? null) === 'agendar' || ! empty($pedido['servicios']) => 'generic',
            default => null,
        };
    }

    /**
     * Lo que le diría alguien de la recepción. Sin botones ni formato:
     * una pregunta corta, con su nombre si lo sabemos.
     */
    public static function message(string $stage, ?string $firstName): string
    {
        $question = match ($stage) {
            'confirm' => '¿te aparto la cita? Solo me falta que me confirmes 😊',
            'hours' => '¿alguna de esas horas te sirvió? Si no, dime qué día y a qué hora te quedaría mejor y lo miramos 😊',
            'person' => '¿tienes preferencia con alguna de las chicas? Si te da igual, dime y te busco el espacio que mejor te quede 😊',
            'day' => '¿qué día te quedaría bien? Dime y te busco espacio 😊',
            'catalog' => '¿pudiste encontrar el servicio que buscabas? Si quieres, cuéntame qué te quieres hacer y te ayudo a encontrarlo 😊',
            default => '¿te ayudo a terminar de agendar tu cita? Cuéntame qué te quieres hacer y para qué día 😊',
        };

        return $firstName === null
            ? mb_strtoupper(mb_substr($question, 0, 2)).mb_substr($question, 2)
            : $firstName.', '.$question;
    }

    /** Si agendó hace poco (por la web, con el equipo...), no hay nada a medias. */
    private function bookedRecently(WhatsappConversation $conversation): bool
    {
        if ($conversation->client_id === null) {
            return false;
        }

        return Appointment::withoutGlobalScopes()
            ->where('client_id', $conversation->client_id)
            ->where('created_at', '>=', now()->subHours(2))
            ->exists();
    }
}
