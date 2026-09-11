<?php

namespace App\Services\Expenses;

use App\Models\Business;
use App\Models\Expense;
use App\Models\RecurringExpense;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Pone en los libros los gastos que se repiten todos los meses.
 *
 * POR QUE SE GENERA SOLO. En el sistema viejo esto era una lista y alguien la
 * copiaba a mano cada primero de mes. Funciono ocho veces al mes, puntual,
 * desde 2025 -- y en mayo de 2026 se corto: cinco meses seguidos sin registrar
 * ~1,2 millones cada uno. El arriendo se causo igual; lo que falto fue el
 * recuerdo.
 *
 * Un paso manual que hay que dar cada treinta dias se deja de dar. Por eso no
 * es un aviso ni un boton: es un gasto que aparece.
 *
 * IDEMPOTENTE POR PAR (plantilla, mes). El indice unico de `expenses` es el
 * que de verdad lo impide; aca solo se evita el error. Sin eso, correr esto
 * dos veces -- o que el cron se dispare dos veces tras un reinicio -- dejaria
 * el arriendo cobrado dos veces, y la forma de enterarse seria que el mes
 * cierre con 840.000 de mas.
 */
class GeneradorDeGastosFijos
{
    /**
     * Genera los de ESE mes para un negocio. Devuelve cuantos quedaron nuevos.
     */
    public function paraElMes(Business $business, CarbonImmutable $mes): int
    {
        $periodo = $mes->format('Y-m');

        $plantillas = RecurringExpense::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->get();

        $creados = 0;

        foreach ($plantillas as $plantilla) {
            if ($this->crear($plantilla, $mes, $periodo)) {
                $creados++;
            }
        }

        return $creados;
    }

    private function crear(RecurringExpense $plantilla, CarbonImmutable $mes, string $periodo): bool
    {
        try {
            DB::transaction(fn () => Expense::create([
                'business_id' => $plantilla->business_id,
                'expense_type_id' => $plantilla->expense_type_id,
                'recurring_expense_id' => $plantilla->id,
                'recurring_period' => $periodo,

                /*
                 * Con el mes en la descripcion, igual que lo escribia ella a
                 * mano: "Arriendo - abril 2026". Sin eso, en una lista de
                 * gastos del año hay doce renglones que dicen "Arriendo" y no
                 * se sabe cual es cual.
                 */
                'description' => $plantilla->description.' - '.$this->mesEnCastellano($mes),

                'value' => $plantilla->value,
                'date' => $plantilla->fechaEn($mes)->toDateString(),
                'payment_method_id' => $plantilla->payment_method_id,
                'location_id' => $plantilla->location_id,
                'scope' => $plantilla->scope,
            ]));

            return true;
        } catch (UniqueConstraintViolationException) {
            // Ya estaba el de ese mes. No es un error: es la garantia
            // funcionando.
            return false;
        }
    }

    private function mesEnCastellano(CarbonImmutable $mes): string
    {
        $nombres = [
            1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
        ];

        return $nombres[$mes->month].' '.$mes->year;
    }
}
