<?php

namespace App\Services\Ia;

use App\Models\Business;
use App\Models\Location;
use App\Models\ResourceSchedule;

/**
 * Quien es el negocio, en palabras, para que el agente no hable como un
 * formulario.
 *
 * Sin esto el modelo no sabe como se llama el local, donde queda, a que hora
 * abre ni con cuanta antelacion se puede cancelar -- y termina contestando
 * "consulta con el establecimiento" a preguntas que el sistema si sabe
 * responder.
 *
 * Lo que NO va aca: servicios, precios y horas libres. Esos salen de las
 * herramientas, que dan el dato de HOY. Meterlos en el prompt seria congelar
 * una lista de precios que cambia y que el modelo repetiria con total
 * seguridad meses despues.
 */
class BusinessProfile
{
    /** El tope del Core. Se recorta aca para no perder la llamada entera. */
    private const MAX_CHARS = 2000;

    public function for(Business $business): string
    {
        $lineas = array_filter([
            'Negocio: '.$business->name.'.',
            $this->about($business),
            $this->sedes($business),
            $this->horario($business),
            $this->politicas($business),
        ]);

        return mb_substr(implode("\n", $lineas), 0, self::MAX_CHARS);
    }

    /** La voz del negocio, si la escribio en su pagina publica. */
    private function about(Business $business): ?string
    {
        $perfil = $business->public_profile ?? [];
        $texto = trim((string) ($perfil['about'] ?? ''));

        return $texto === '' ? null : 'Sobre el negocio: '.$texto;
    }

    private function sedes(Business $business): ?string
    {
        $sedes = Location::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->orderByDesc('is_primary')
            ->get();

        if ($sedes->isEmpty()) {
            return null;
        }

        if ($sedes->count() === 1) {
            $sede = $sedes->first();

            return 'Dirección: '.($sede->address ?: $business->address ?: 'no registrada').'.';
        }

        /*
         * Con varias sedes hay que DECIRSELO: si el agente no sabe que hay
         * mas de un local, nunca pregunta a cual va y la herramienta de
         * disponibilidad le responde que falta la sede, una y otra vez.
         */
        $lista = $sedes->map(fn (Location $s) => $s->name.($s->address ? ' ('.$s->address.')' : ''))
            ->implode('; ');

        return 'Este negocio tiene varias sedes: '.$lista
            .'. Pregunta siempre a cuál sede quiere ir antes de buscar horas.';
    }

    /** El horario real del equipo, que es el que manda. */
    private function horario(Business $business): ?string
    {
        $dias = ResourceSchedule::withoutGlobalScopes()
            ->join('resources', 'resources.id', '=', 'resource_schedules.resource_id')
            ->where('resources.business_id', $business->id)
            ->where('resources.is_active', true)
            ->selectRaw('resource_schedules.weekday, MIN(start_time) as abre, MAX(end_time) as cierra')
            ->groupBy('resource_schedules.weekday')
            ->orderBy('resource_schedules.weekday')
            ->get();

        if ($dias->isEmpty()) {
            return null;
        }

        $nombres = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

        $partes = $dias->map(fn ($d) => ($nombres[$d->weekday] ?? '?').' '
            .substr((string) $d->abre, 0, 5).'-'.substr((string) $d->cierra, 0, 5));

        return 'Horario: '.$partes->implode(', ').'. Los demás días está cerrado.';
    }

    private function politicas(Business $business): string
    {
        $reserva = (int) $business->schedulingSetting('min_booking_notice_min');
        $cancela = (int) $business->schedulingSetting('min_cancellation_notice_min');

        $partes = [];

        if ($reserva > 0) {
            $partes[] = 'se reserva con al menos '.$this->humano($reserva).' de antelación';
        }

        if ($cancela > 0) {
            $partes[] = 'se cancela o se cambia con al menos '.$this->humano($cancela).' de antelación';
        }

        return $partes === []
            ? 'Zona horaria: '.$business->businessTimezone().'.'
            : 'Reglas: '.implode(', ', $partes).'. Zona horaria: '.$business->businessTimezone().'.';
    }

    private function humano(int $minutos): string
    {
        if ($minutos < 60) {
            return $minutos.' minutos';
        }

        $horas = intdiv($minutos, 60);

        return $horas.' hora'.($horas === 1 ? '' : 's');
    }
}
