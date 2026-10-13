<?php

namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\RecurringExpense;
use Carbon\CarbonInterface;

/**
 * Los gastos de un período para la rentabilidad (Módulo 4 de Fran, §24 a §30):
 * - el manual cuenta el día de su fecha; el recurrente se reparte en los días que cubre (§28);
 * - con producto o ciudad es específico de ellos (§30); sin ellos es general y va después del margen de contribución (§29);
 * - los anulados no cuentan (§83: siguen a la vista, pero no suman).
 */
class ExpenseLedger
{
    /**
     * @return array{items: array<int, array>, total: float, general: float, assigned: float}
     */
    public function forRange(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $items = [];

        foreach (Expense::with('category:id,name')->whereNull('voided_at')->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])->get() as $e) {
            $items[] = $this->item('manual', $e->id, $e->concept, $e->category?->name, $e->product_id, $e->city_id, $e->amount_usd);
        }
        foreach (RecurringExpense::with(['category:id,name', 'versions'])->get() as $r) {
            $amount = $r->accrued($from, $to);
            if ($amount > 0) {
                $items[] = $this->item('recurring', $r->id, $r->name, $r->category?->name, $r->product_id, $r->city_id, $amount);
            }
        }

        $sum = fn (string $scope) => round(array_sum(array_column(array_filter($items, fn ($i) => $i['scope'] === $scope), 'amount_usd')), 2);

        return [
            'items' => $items,
            'total' => round(array_sum(array_column($items, 'amount_usd')), 2),
            'general' => $sum('general'),
            'assigned' => $sum('assigned'),
        ];
    }

    private function item(string $type, int $id, string $name, ?string $category, ?int $productId, ?int $cityId, float $amount): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'product_id' => $productId,
            'city_id' => $cityId,
            'scope' => $productId || $cityId ? 'assigned' : 'general',
            'amount_usd' => round($amount, 2),
        ];
    }
}
