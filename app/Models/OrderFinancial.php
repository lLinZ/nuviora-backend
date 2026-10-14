<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La economía de una orden (Módulo 4 de Fran, entrega 1). Solo la escribe App\Services\Finance\OrderEconomics: los
 * reportes leen de aquí y nunca la editan a mano.
 */
class OrderFinancial extends Model
{
    public const DELIVERED = 'delivered';
    public const NOT_DELIVERED = 'not_delivered';
    public const OPEN = 'open';

    public const KIND_NORMAL = 'normal';
    public const KIND_EXCHANGE = 'exchange';
    public const KIND_RETURN = 'return';

    /** De dónde sale el costo del producto (entrega 2e): de sus lotes, del catálogo (provisional) o de ambos. */
    public const COGS_LOTS = 'lots';
    public const COGS_CATALOG = 'catalog';
    public const COGS_MIXED = 'mixed';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'created_date' => 'date',
        'delivered_date' => 'date',
        'cost_date' => 'date',
        'revenue_main' => 'float',
        'revenue_upsell' => 'float',
        'revenue_refunded' => 'float',
        'cogs_main' => 'float',
        'cogs_upsell' => 'float',
        'logistics_cost' => 'float',
        'cogs_is_provisional' => 'boolean',
        'delivery_cost' => 'float',
        'delivery_legacy_cost' => 'float',
        'commission_seller' => 'float',
        'commission_upsell' => 'float',
        'commission_leader' => 'float',
        'commission_deliverer' => 'float',
        'stale_at' => 'datetime',
        'computed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /** Ingreso neto: lo entregado menos lo devuelto (R3). */
    public function netRevenue(): float
    {
        return round($this->revenue_main + $this->revenue_upsell - $this->revenue_refunded, 2);
    }

    /** Todo lo que costó la orden, entregada o no (§5). */
    public function totalCost(): float
    {
        return round($this->cogs_main + $this->cogs_upsell + $this->logistics_cost + $this->delivery_cost + $this->commission_seller
            + $this->commission_upsell + $this->commission_leader + $this->commission_deliverer, 2);
    }
}
