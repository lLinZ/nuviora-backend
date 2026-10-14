<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cómo se paga a un repartidor propio (Módulo 4 de Fran, R11): un monto fijo por entrega, o su tarifario por distancia
 * desde un almacén que elige el Admin (como las agencias, Módulo 3).
 */
class DelivererCostSetting extends Model
{
    public const FIXED = 'fixed';
    public const TARIFF = 'tariff';
    public const MODES = [self::FIXED => 'Monto fijo por entrega', self::TARIFF => 'Tarifario por distancia'];

    protected $fillable = ['user_id', 'mode', 'fixed_amount', 'warehouse_id', 'updated_by'];

    protected $casts = [
        'fixed_amount' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
