<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración de inventario de un producto (Módulo 5 de Fran, §5, §6, §13, §24): su estado comercial y, si se quiere,
 * cobertura, días de seguridad y % de Sin Stock propios, que prevalecen sobre los globales. Sin fila = Activo y globales.
 */
class ProductInventorySetting extends Model
{
    public const ACTIVE = 'active';
    public const PAUSED = 'paused';
    public const LIQUIDATION = 'liquidation';
    public const DISCONTINUED = 'discontinued';

    /** §13: cómo trata el plan a cada estado */
    public const STATUSES = [
        self::ACTIVE => 'Activo',
        self::PAUSED => 'Pausado',
        self::LIQUIDATION => 'Liquidación',
        self::DISCONTINUED => 'Descontinuado',
    ];

    protected $fillable = ['product_id', 'commercial_status', 'coverage_days', 'safety_days', 'sin_stock_pct', 'notes', 'updated_by'];

    protected $casts = [
        'coverage_days' => 'float',
        'safety_days' => 'float',
        'sin_stock_pct' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
