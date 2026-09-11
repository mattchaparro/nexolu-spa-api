<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Services\Expenses\GeneradorDeGastosFijos;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Los gastos que se repiten todos los meses.
 *
 * Arriendo, agua, internet, el servidor. Se configuran una vez y aparecen
 * solos en los libros; ver GeneradorDeGastosFijos para por que se generan en
 * vez de recordarse.
 */
class RecurringExpenseController
{
    public function index(Request $request): JsonResponse
    {
        $plantillas = RecurringExpense::with(['expenseType', 'paymentMethod'])
            ->orderByDesc('is_active')
            ->orderByDesc('value')
            ->get();

        $mes = CarbonImmutable::now($request->user()->business->businessTimezone())->format('Y-m');

        // Cuales de este mes ya estan puestos: es lo primero que alguien
        // quiere saber al abrir esta pantalla.
        $yaGenerados = Expense::whereNotNull('recurring_expense_id')
            ->where('recurring_period', $mes)
            ->pluck('recurring_expense_id')
            ->all();

        return response()->json([
            'period' => $mes,
            'data' => $plantillas->map(fn (RecurringExpense $r) => [
                'id' => $r->id,
                'description' => $r->description,
                'value' => (float) $r->value,
                'day_of_month' => $r->day_of_month,
                'expense_type_id' => $r->expense_type_id,
                'expense_type' => $r->expenseType?->name,
                'payment_method_id' => $r->payment_method_id,
                'location_id' => $r->location_id,
                'scope' => $r->scope,
                'is_active' => (bool) $r->is_active,
                'generated_this_period' => in_array($r->id, $yaGenerados, true),
            ])->values(),
            'monthly_total' => round((float) $plantillas->where('is_active', true)->sum('value'), 2),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $plantilla = RecurringExpense::create(
            $this->validated($request) + ['business_id' => $request->user()->business_id],
        );

        return response()->json(['id' => $plantilla->id], 201);
    }

    public function update(Request $request, RecurringExpense $recurringExpense): JsonResponse
    {
        /*
         * Cambiar la plantilla NO reescribe lo ya generado.
         *
         * Subir el arriendo en octubre no cambia lo que se pago en septiembre:
         * eso seria reescribir la contabilidad de un mes cerrado. La plantilla
         * dice cuanto sera de aca en adelante.
         */
        $recurringExpense->update($this->validated($request));

        return response()->json(['id' => $recurringExpense->id]);
    }

    public function destroy(RecurringExpense $recurringExpense): JsonResponse
    {
        // Los gastos que ya genero se quedan: son plata que salio.
        $recurringExpense->delete();

        return response()->json(['deleted' => true]);
    }

    /** Generar los de este mes ahora, sin esperar a la madrugada. */
    public function generate(Request $request, GeneradorDeGastosFijos $generador): JsonResponse
    {
        $business = $request->user()->business;
        $mes = CarbonImmutable::now($business->businessTimezone());

        $creados = $generador->paraElMes($business, $mes);

        return response()->json([
            'created' => $creados,
            'message' => $creados === 0
                ? 'Los de este mes ya estaban puestos.'
                : ($creados === 1 ? 'Se puso 1 gasto.' : "Se pusieron {$creados} gastos."),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'value' => ['required', 'numeric', 'min:0.01'],
            // Hasta 31: un dia 31 en febrero se recorta al ultimo del mes.
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'expense_type_id' => ['required', 'integer'],
            'scope' => ['required', Rule::in(Expense::scopes())],
            'location_id' => ['nullable', 'integer'],
            'payment_method_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
