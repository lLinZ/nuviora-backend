<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Traslado entre almacenes (Módulo 5 de Fran, §15, §92, R2). Al salir, la mercancía pasa del origen al almacén virtual
 * "En tránsito": ya no está disponible en el origen y todavía no está recibida en el destino. Al marcarlo "Recibido"
 * entra al destino. Puede depender de una compra que todavía no llega (§58, §92).
 */
class InventoryTransfer extends Model
{
    public const REFERENCE = 'InventoryTransfer';

    public const PENDING = 'pending';
    public const PREPARING = 'preparing';
    public const IN_TRANSIT = 'in_transit';
    public const RECEIVED = 'received';
    public const CANCELLED = 'cancelled';

    /** Los estados del §92, en orden */
    public const STATUSES = [
        self::PENDING => 'Pendiente',
        self::PREPARING => 'Preparando',
        self::IN_TRANSIT => 'En tránsito',
        self::RECEIVED => 'Recibido',
        self::CANCELLED => 'Cancelado',
    ];

    /** Los que todavía no salieron del origen */
    public const NOT_DISPATCHED = [self::PENDING, self::PREPARING];

    protected $fillable = [
        'from_warehouse_id', 'to_warehouse_id', 'status', 'purchase_id', 'notes',
        'dispatched_at', 'dispatched_by', 'received_at', 'received_by', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'created_by',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryTransferLine::class, 'transfer_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchase::class, 'purchase_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function units(): int
    {
        return (int) $this->lines->sum('quantity');
    }

    /** Lo que se audita (§95) */
    public function snapshot(): array
    {
        return [
            'status' => $this->status,
            'from' => $this->fromWarehouse?->name,
            'to' => $this->toWarehouse?->name,
            'purchase_id' => $this->purchase_id,
            // Entrega 4 (§58, §118): uno que reparte una compra no sale hasta que la compra se recibe
            'purchase_status' => $this->purchase_id ? $this->purchase?->status : null,
            'waiting_purchase' => $this->purchase_id !== null && $this->purchase?->status !== InventoryPurchase::RECEIVED,
            'lines' => $this->lines->map(fn ($l) => [
                'product' => $l->product?->title,
                'variant' => $l->variant?->title,
                'quantity' => (int) $l->quantity,
            ])->values()->all(),
            'notes' => $this->notes,
        ];
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'from_warehouse_id' => $this->from_warehouse_id,
            'from_warehouse' => $this->fromWarehouse?->name,
            'to_warehouse_id' => $this->to_warehouse_id,
            'to_warehouse' => $this->toWarehouse?->name,
            'purchase_id' => $this->purchase_id,
            // Entrega 4 (§58, §118): uno que reparte una compra no sale hasta que la compra se recibe
            'purchase_status' => $this->purchase_id ? $this->purchase?->status : null,
            'waiting_purchase' => $this->purchase_id !== null && $this->purchase?->status !== InventoryPurchase::RECEIVED,
            'units' => $this->units(),
            'lines' => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'product' => $l->product?->title,
                'variant_id' => $l->variant_id,
                'variant' => $l->variant?->title,
                'quantity' => (int) $l->quantity,
            ])->values()->all(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
            'created_by' => $this->creator?->names,
            'dispatched_at' => $this->dispatched_at?->format('Y-m-d H:i'),
            'received_at' => $this->received_at?->format('Y-m-d H:i'),
            'cancelled_at' => $this->cancelled_at?->format('Y-m-d H:i'),
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
