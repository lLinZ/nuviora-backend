<?php

namespace App\Models;

use App\Services\Finance\TransferTrips;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Viaje de traslado (Módulo 4 de Fran, §13, §14, §15, R8): agrupa traslados de varios destinos y productos con un solo
 * costo (mano de obra, combustible, otros y desgaste). Su total en USD se reparte entre todas las unidades movidas
 * (unit_cost_usd), que se suma a la logística de las capas de destino. No se borra: se anula con motivo (§83).
 */
class InventoryTransferTrip extends Model
{
    protected $fillable = [
        'trip_date', 'description', 'currency', 'rate', 'labor', 'fuel', 'other', 'wear', 'total', 'total_usd', 'wear_mode', 'wear_value',
        'units', 'unit_cost_usd', 'company_account_id', 'financial_account_id', 'receipt_path', 'notes', 'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected $casts = [
        'trip_date' => 'date:Y-m-d',
        'rate' => 'float',
        'labor' => 'float',
        'fuel' => 'float',
        'other' => 'float',
        'wear' => 'float',
        'total' => 'float',
        'total_usd' => 'float',
        'wear_value' => 'float',
        'units' => 'integer',
        'unit_cost_usd' => 'float',
        'voided_at' => 'datetime',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'trip_id');
    }

    /** La cuenta de la caja de donde se pagó el viaje (entrega 3). */
    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function snapshot(): array
    {
        return [
            'trip_date' => $this->trip_date?->format('Y-m-d'),
            'description' => $this->description,
            'currency' => $this->currency,
            'rate' => $this->rate,
            'labor' => $this->labor,
            'fuel' => $this->fuel,
            'other' => $this->other,
            'wear' => $this->wear,
            'total' => $this->total,
            'total_usd' => $this->total_usd,
            'units' => $this->units,
            'unit_cost_usd' => $this->unit_cost_usd,
            'movements' => $this->movements()->pluck('id')->sort()->values()->implode(', '),
            'account' => $this->account?->name,
            'notes' => $this->notes,
            'has_receipt' => $this->receipt_path !== null,
        ];
    }

    public function toPayload(): array
    {
        $movements = $this->relationLoaded('movements') ? $this->movements : $this->movements()->with(['product:id,title,showable_name', 'variant:id,title',
            'fromWarehouse:id,name', 'toWarehouse:id,name'])->get();

        return [
            'id' => $this->id,
            'trip_date' => $this->trip_date?->format('Y-m-d'),
            'description' => $this->description,
            'currency' => $this->currency,
            'rate' => $this->rate,
            'labor' => $this->labor,
            'fuel' => $this->fuel,
            'other' => $this->other,
            'wear' => $this->wear,
            'total' => $this->total,
            'total_usd' => $this->total_usd,
            'wear_mode' => $this->wear_mode,
            'wear_value' => $this->wear_value,
            'wear_label' => TransferTrips::wearLabel($this->wear_mode, $this->wear_value),
            'units' => $this->units,
            'unit_cost_usd' => $this->unit_cost_usd,
            'financial_account_id' => $this->financial_account_id,
            'account' => $this->account?->name,
            'notes' => $this->notes,
            'has_receipt' => $this->receipt_path !== null,
            'voided_at' => $this->voided_at?->format('Y-m-d H:i'),
            'voided_by' => $this->voider?->names,
            'void_reason' => $this->void_reason,
            'created_by' => $this->creator?->names,
            'destinations' => $movements->pluck('toWarehouse.name')->filter()->unique()->values(),
            'movements' => $movements->map(fn (InventoryMovement $m) => TransferTrips::movementPayload($m))->values(),
        ];
    }
}
