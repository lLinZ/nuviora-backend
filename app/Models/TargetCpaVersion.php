<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una versión del CPA financiero de un producto (+ ciudad) (Módulo 4 de Fran, §73). No se edita: cada cálculo es otra. */
class TargetCpaVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'period_from' => 'date:Y-m-d',
        'period_to' => 'date:Y-m-d',
        'effectiveness' => 'float',
        'revenue' => 'float',
        'variable_costs' => 'float',
        'cogs' => 'float',
        'purchases' => 'float',
        'spend' => 'float',
        'meta_cpa' => 'float',
        'target_pct' => 'float',
        'break_even' => 'float',
        'target' => 'float',
        'fully_loaded' => 'float',
        'viable' => 'boolean',
        'computed_at' => 'datetime',
    ];

    public function toPayload(): array
    {
        return [
            'id' => $this->id, 'product_id' => $this->product_id, 'city_id' => $this->city_id, 'version' => $this->version,
            'period_from' => $this->period_from?->format('Y-m-d'), 'period_to' => $this->period_to?->format('Y-m-d'),
            'orders_created' => $this->orders_created, 'orders_delivered' => $this->orders_delivered, 'effectiveness' => $this->effectiveness,
            'revenue' => $this->revenue, 'variable_costs' => $this->variable_costs, 'contribution' => round($this->revenue - $this->variable_costs, 2),
            'cogs' => $this->cogs, 'purchases' => $this->purchases, 'spend' => $this->spend, 'meta_cpa' => $this->meta_cpa,
            'target_type' => $this->target_type, 'target_pct' => $this->target_pct, 'break_even' => $this->break_even, 'target' => $this->target,
            'fully_loaded' => $this->fully_loaded, 'viable' => $this->viable, 'trigger' => $this->trigger,
            'computed_at' => $this->computed_at?->format('Y-m-d H:i'),
        ];
    }
}
