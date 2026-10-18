<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Compra de inventario (Módulo 4 de Fran, §9, §16, R6): nace en el ingreso de inventario. Sus movimientos llevan
 * reference_type InventoryPurchase, y el libro de lotes crea con ellos un lote "purchase" por variante (R7) con el costo
 * unitario de la compra (§9).
 */
class InventoryPurchase extends Model
{
    public const REFERENCE = 'InventoryPurchase';
    public const PAYMENT_TYPES = ['cash' => 'Al contado', 'credit' => 'A crédito'];

    /**
     * Módulo 5 (§16, §90, R3): una compra puede quedar "por recibir", con la fecha en que debería llegar, sin sumar stock
     * ni crear lote, deuda o pago. Al recibirla, con la cantidad y el costo reales, pasa lo de siempre.
     */
    public const PENDING = 'pending';
    public const RECEIVED = 'received';
    public const CANCELLED = 'cancelled';
    public const STATUSES = [self::PENDING => 'Por recibir', self::RECEIVED => 'Recibida', self::CANCELLED => 'Cancelada'];

    protected $fillable = [
        'supplier_id', 'product_id', 'warehouse_id', 'quantity', 'total_amount', 'currency', 'rate', 'total_usd', 'unit_cost_usd',
        'payment_type', 'purchased_at', 'due_date', 'terms', 'receipt_path', 'notes', 'created_by',
        'status', 'expected_at', 'received_at', 'expected_quantity', 'expected_total_amount', 'expected_variants',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'total_amount' => 'float',
        'rate' => 'float',
        'total_usd' => 'float',
        'unit_cost_usd' => 'float',
        'purchased_at' => 'datetime',
        'due_date' => 'date:Y-m-d',
        'expected_at' => 'date:Y-m-d',
        'received_at' => 'datetime',
        'expected_quantity' => 'integer',
        'expected_total_amount' => 'float',
        'expected_variants' => 'array',
        'cancelled_at' => 'datetime',
    ];

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(InventoryLot::class, 'purchase_id');
    }

    public function payable()
    {
        return $this->hasOne(AccountPayable::class, 'source_id')->where('source_type', AccountPayable::SOURCE_PURCHASE);
    }

    /** Lo que se audita: los campos que cuentan. */
    public function snapshot(): array
    {
        return [
            'supplier' => $this->supplier?->name,
            'product' => $this->product?->title,
            'warehouse' => $this->warehouse?->name,
            'quantity' => $this->quantity,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'rate' => $this->rate,
            'total_usd' => $this->total_usd,
            'unit_cost_usd' => $this->unit_cost_usd,
            'payment_type' => $this->payment_type,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'terms' => $this->terms,
            'has_receipt' => $this->receipt_path !== null,
            'status' => $this->status,
            'expected_at' => $this->expected_at?->format('Y-m-d'),
            'expected_quantity' => $this->expected_quantity,
            'expected_total_amount' => $this->expected_total_amount,
        ];
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->supplier?->name,
            'product_id' => $this->product_id,
            'product' => $this->product?->title,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->warehouse?->name,
            'quantity' => $this->quantity,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'rate' => $this->rate,
            'total_usd' => $this->total_usd,
            'unit_cost_usd' => $this->unit_cost_usd,
            'payment_type' => $this->payment_type,
            'payment_type_label' => self::PAYMENT_TYPES[$this->payment_type] ?? $this->payment_type,
            'purchased_at' => $this->purchased_at?->format('Y-m-d H:i'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'terms' => $this->terms,
            'notes' => $this->notes,
            'has_receipt' => $this->receipt_path !== null,
            'created_by' => $this->creator?->names,
            'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'expected_at' => $this->expected_at?->format('Y-m-d'),
            'received_at' => $this->received_at?->format('Y-m-d H:i'),
            'expected_quantity' => $this->expected_quantity,
            'expected_total_amount' => $this->expected_total_amount,
            'expected_variants' => $this->expected_variants,
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
