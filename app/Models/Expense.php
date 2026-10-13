<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gasto manual (Módulo 4 de Fran, §24). Con producto o ciudad es un gasto específico de ese producto o esa ciudad (§30);
 * sin ellos es general y se descuenta después del margen de contribución (§29). No se borra: se anula con motivo (§83).
 */
class Expense extends Model
{
    public const CURRENCIES = ['USD', 'USDT', 'VES'];

    protected $fillable = [
        'concept', 'expense_category_id', 'amount', 'currency', 'rate', 'amount_usd', 'expense_date', 'description', 'receipt_path',
        'product_id', 'city_id', 'warehouse_id', 'agency_id', 'supplier_id', 'recurring_expense_id', 'voided_at', 'voided_by',
        'void_reason', 'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'rate' => 'float',
        'amount_usd' => 'float',
        'expense_date' => 'date:Y-m-d',
        'voided_at' => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * §31 a §33: el equivalente en USD. USD y USDT valen 1 a 1; en bolívares, la tasa la escribe el Admin (R2) como
     * bolívares por dólar.
     */
    public static function toUsd(float $amount, string $currency, ?float $rate): array
    {
        $rate = in_array($currency, ['USD', 'USDT'], true) ? 1.0 : (float) $rate;

        return ['rate' => $rate, 'amount_usd' => $rate > 0 ? round($amount / $rate, 2) : 0.0];
    }

    /** Lo que se audita y se muestra: los campos que cuentan, sin fechas internas. */
    public function snapshot(): array
    {
        return [
            'concept' => $this->concept,
            'category' => $this->category?->name,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'rate' => $this->rate,
            'amount_usd' => $this->amount_usd,
            'expense_date' => $this->expense_date?->format('Y-m-d'),
            'description' => $this->description,
            'product' => $this->product?->title,
            'city' => $this->city?->name,
            'warehouse' => $this->warehouse?->name,
            'agency' => $this->agency?->names,
            'supplier' => $this->supplier?->name,
            'has_receipt' => $this->receipt_path !== null,
        ];
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'concept' => $this->concept,
            'expense_category_id' => $this->expense_category_id,
            'category' => $this->category?->name,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'rate' => $this->rate,
            'amount_usd' => $this->amount_usd,
            'expense_date' => $this->expense_date?->format('Y-m-d'),
            'description' => $this->description,
            'has_receipt' => $this->receipt_path !== null,
            'product_id' => $this->product_id,
            'product' => $this->product?->title,
            'city_id' => $this->city_id,
            'city' => $this->city?->name,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->warehouse?->name,
            'agency_id' => $this->agency_id,
            'agency' => $this->agency?->names,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->supplier?->name,
            'scope' => $this->product_id || $this->city_id ? 'assigned' : 'general',
            'voided_at' => $this->voided_at?->format('Y-m-d H:i'),
            'voided_by' => $this->voider?->names,
            'void_reason' => $this->void_reason,
            'created_by' => $this->creator?->names,
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
