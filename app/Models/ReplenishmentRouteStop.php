<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un almacén de una ruta logística, en su orden sugerido (Módulo 5 de Fran, §60, §63). */
class ReplenishmentRouteStop extends Model
{
    protected $fillable = ['route_id', 'warehouse_id', 'position'];

    protected $casts = ['position' => 'integer'];

    public function route(): BelongsTo
    {
        return $this->belongsTo(ReplenishmentRoute::class, 'route_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
