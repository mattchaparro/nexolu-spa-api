<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Expense;
use App\Models\ExpenseType;
use App\Support\ImageStorage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'scope' => ['nullable', Rule::in(Expense::scopes())],
        ]);

        $tz = $request->user()->business->businessTimezone();
        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'], $tz)
            : CarbonImmutable::now($tz)->startOfMonth();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $tz) : CarbonImmutable::now($tz);

        $expenses = Expense::with(['type', 'paymentMethod'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when(isset($data['scope']), fn ($q) => $q->where('scope', $data['scope']))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $expenses->map(fn (Expense $e) => [
                'id' => $e->id,
                'date' => $e->date?->toDateString(),
                'description' => $e->description,
                'value' => (float) $e->value,
                'scope' => $e->scope,
                'type' => $e->type?->name,
                'expense_type_id' => $e->expense_type_id,
                'payment_method' => $e->paymentMethod?->name,
                'payment_method_id' => $e->payment_method_id,
                'receipt_url' => ImageStorage::url($e->receipt_path),
            ]),
            'totals' => [
                'operacional' => (float) $expenses->where('scope', Expense::SCOPE_OPERATIONAL)->sum('value'),
                'administrativo' => (float) $expenses->where('scope', Expense::SCOPE_ADMINISTRATIVE)->sum('value'),
                'total' => (float) $expenses->sum('value'),
            ],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->user()->business;
        $data = $this->validated($request);

        $expense = Expense::create($data + [
            'business_id' => $business->id,
            'created_by_user_id' => $request->user()->id,
        ]);

        if ($request->hasFile('receipt')) {
            $expense->update([
                'receipt_path' => ImageStorage::store($request->file('receipt'), $business->id, 'gastos'),
            ]);
        }

        return response()->json(['id' => $expense->id], 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $business = $request->user()->business;
        $expense->update($this->validated($request));

        if ($request->hasFile('receipt')) {
            $anterior = $expense->receipt_path;
            $expense->update([
                'receipt_path' => ImageStorage::store($request->file('receipt'), $business->id, 'gastos'),
            ]);
            ImageStorage::delete($anterior);
        }

        return response()->json(['id' => $expense->id]);
    }

    public function destroy(Expense $expense): JsonResponse
    {
        // Borrado suave: un gasto que ya entro en un cierre no puede
        // desaparecer sin dejar rastro, o el cuadre de ese dia deja de
        // reproducirse.
        $expense->delete();

        return response()->json(['message' => 'Gasto eliminado.']);
    }

    public function types(Request $request): JsonResponse
    {
        return response()->json(
            ExpenseType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
        );
    }

    public function storeType(Request $request): JsonResponse
    {
        $business = $request->user()->business;

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('expense_types', 'name')->where('business_id', $business->id),
            ],
        ]);

        $type = ExpenseType::create($data + ['business_id' => $business->id, 'is_active' => true]);

        return response()->json(['id' => $type->id, 'name' => $type->name], 201);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'value' => ['required', 'numeric', 'min:0.01'],
            // El alcance decide si el gasto toca la caja del dia. Es un enum
            // cerrado, no texto libre: el POS aprendio por las malas que
            // restringirlo solo en el formulario deja entrar basura por API.
            'scope' => ['required', Rule::in(Expense::scopes())],
            'expense_type_id' => ['nullable', 'integer'],
            'payment_method_id' => ['nullable', 'integer'],
            'receipt' => ImageStorage::rules(),
        ]);
    }
}
