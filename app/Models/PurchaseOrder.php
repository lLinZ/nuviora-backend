<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'warehouse_id',
        'created_by',
        'reference_number',
        'status',
        'expected_at',
        'received_at',
        'total_usd',
        'total_ves',
        'notes',
    ];

    protected $casts = [
        'expected_at'  => 'date',
        'received_at'  => 'datetime',
        'total_usd'    => 'float',
        'total_ves'    => 'float',
    ];

    /**
     * Supplier for this PO
     */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Destination warehouse where goods will be received
     */
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * User who created the PO
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Line items
     */
    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Recalculate and persist totals from items
     */
    public function recalculateTotals(): void
    {
        $this->total_usd = $this->items()->sum(\DB::raw('unit_cost_usd * quantity_ordered'));
        $this->total_ves = $this->items()->sum(\DB::raw('unit_cost_ves * quantity_ordered'));
        $this->saveQuietly();
    }

    /**
     * Check whether this PO is fully received
     */
    public function isFullyReceived(): bool
    {
        return $this->items->every(fn ($item) => $item->quantity_received >= $item->quantity_ordered);
    }

    // ── Status scopes ────────────────────────────────────────────────────────────

    public function scopeDraft($q)    { return $q->where('status', 'draft'); }
    public function scopeOpen($q)     { return $q->whereIn('status', ['draft', 'sent', 'confirmed', 'partial']); }
    public function scopeClosed($q)   { return $q->whereIn('status', ['received', 'cancelled']); }

    /**
     * Human-readable status label (Spanish)
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'draft'     => 'Borrador',
            'sent'      => 'Enviada',
            'confirmed' => 'Confirmada',
            'partial'   => 'Recibida Parcial',
            'received'  => 'Recibida',
            'cancelled' => 'Cancelada',
            default     => ucfirst($this->status),
        };
    }

    protected $appends = ['status_label'];

    /**
     * Auto-generate the next reference number: OC-YYYY-NNN
     */
    public static function nextReferenceNumber(): string
    {
        $year  = now()->format('Y');
        $count = static::whereYear('created_at', $year)->count() + 1;
        return sprintf('OC-%s-%03d', $year, $count);
    }
}
