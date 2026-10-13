<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Margen objetivo de un producto (Módulo 4 de Fran, §66, R13): % sobre ventas o sobre costo. Sin fila: 20 % sobre ventas. */
class ProductFinancialSetting extends Model
{
    public const DEFAULT_TYPE = 'sales';
    public const DEFAULT_PCT = 20.0;
    public const TYPES = ['sales' => '% sobre ventas', 'cost' => '% sobre costo'];

    protected $fillable = ['product_id', 'target_type', 'target_pct', 'updated_by'];

    protected $casts = ['target_pct' => 'float'];

    /** @return array<int, array{type: string, pct: float}> */
    public static function forProducts(array $productIds): array
    {
        $rows = self::whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $out = [];
        foreach ($productIds as $id) {
            $r = $rows->get($id);
            $out[$id] = ['type' => $r?->target_type ?? self::DEFAULT_TYPE, 'pct' => $r?->target_pct ?? self::DEFAULT_PCT];
        }

        return $out;
    }
}
