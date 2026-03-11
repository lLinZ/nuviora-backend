<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Setting;
use App\Models\Status;
use App\Models\OrderActivityLog;
use App\Models\OrderUpdate;

class Order extends Model
{
    //
    use HasFactory;
 
    protected $casts = [
        'change_payment_details' => 'array',
        'scheduled_for'          => 'datetime',
    ];

    // Relationship methods follow...

    public function postponements()
    {
        return $this->hasMany(\App\Models\OrderPostponement::class);
    }
    public function products()
    {
        return $this->hasMany(OrderProduct::class);
    }
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }
    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
    public function updates()
    {
        return $this->hasMany(OrderUpdate::class);
    }
    public function deliveryReviews()
    {
        return $this->hasMany(OrderDeliveryReview::class);
    }
    public function locationReviews()
    {
        return $this->hasMany(OrderLocationReview::class);
    }
    public function rejectionReviews()
    {
        return $this->hasMany(OrderRejectionReview::class);
    }

    public function cancellations()
    {
        return $this->hasMany(OrderCancellation::class);
    }

    public function whatsappMessages()
    {
        return $this->hasMany(WhatsappMessage::class);
    }
    public function deliverer()
    {
        return $this->belongsTo(\App\Models\User::class, 'deliverer_id');
    }

    public function payments()
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function paymentReceipts()
    {
        return $this->hasMany(PaymentReceipt::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function province()
    {
        return $this->belongsTo(Province::class);
    }

    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }



    public function getVesPriceAttribute()
    {
        $rate = (float) Setting::get('rate_binance_usd', 0);
        return $this->current_total_price * $rate;
    }

    public function getBcvEquivalenceAttribute()
    {
        $ves = $this->ves_price;
        $rateBcv = (float) Setting::get('rate_bcv_usd', 0);
        return $rateBcv > 0 ? $ves / $rateBcv : 0;
    }

    protected $fillable = [
        'order_id',
        'order_number',
        'name',
        'current_total_price',
        'currency',
        'processed_at',
        'client_id',
        'status_id',
        'previous_status_id', // 👈 Guarda el status anterior a "Sin Stock" para poder restaurarlo
        'reset_count',         // 👈 Cuántas veces fue reseteada a Nuevo al cerrar tienda; si > 0 se cancela
        'cancelled_at',
        'scheduled_for',
        'agent_id',
        'deliverer_id',
        'payment_method',
        'exchange_rate',
        'payment_receipt',
        'reminder_at',
        'shop_id',
        'was_shipped',
        'shipped_at',
        'city_id',
        'province_id',
        'agency_id',
        'delivery_cost',
        'cash_received',
        'change_amount',
        'change_covered_by',
        'change_amount_company',
        'change_amount_agency',
        'change_method_company',
        'change_method_agency',
        'novedad_type',
        'novedad_description',
        'novedad_resolution',
        'change_rate',
        'is_return',
        'is_exchange',
        'parent_order_id',
    ];

    /**
     * Relationship to extra details (workaround for ALTER privileges)
     */
    public function changeExtra()
    {
        return $this->hasOne(OrderChangeExtra::class);
    }

    /**
     * Get the parent order (if this is a return)
     */
    public function parentOrder()
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    /**
     * Get return orders created from this order
     */
    public function returnOrders()
    {
        return $this->hasMany(Order::class, 'parent_order_id');
    }
    
    // Virtual attributes
    protected $appends = ['ves_price', 'bcv_equivalence', 'change_payment_details', 'change_receipt', 'whatsapp_unread_count'];


    public function getChangePaymentDetailsAttribute()
    {
        return $this->changeExtra ? $this->changeExtra->change_payment_details : null;
    }

    public function getChangeReceiptAttribute()
    {
        return $this->changeExtra ? $this->changeExtra->change_receipt : null;
    }

    public function getWhatsappUnreadCountAttribute()
    {
        return $this->whatsappMessages()
            ->where('is_from_client', true)
            ->where('status', '!=', 'read')
            ->count();
    }

    /**
     * Helper to check stock availability for an order
     */
    public function getStockDetails()
    {
        // 1. Determine relevant warehouse
        $warehouseId = null;
        if ($this->agency_id) {
            $warehouseId = \App\Models\Warehouse::where('user_id', $this->agency_id)->first()?->id;
        }
        
        // Fallback to main warehouse if no agency warehouse found
        if (!$warehouseId) {
            $warehouseId = \App\Models\Warehouse::where('is_main', true)->first()?->id;
        }

        if (!$warehouseId) {
            return ['has_warning' => false, 'items' => []]; 
        }

        // 2. Get inventory for this warehouse
        $inventory = \App\Models\Inventory::where('warehouse_id', '=', $warehouseId)
            ->whereIn('product_id', $this->products->pluck('product_id'))
            ->get()
            ->keyBy('product_id');

        $items = [];
        $hasWarning = false;

        foreach ($this->products as $op) {
            $available = $inventory->get($op->product_id)->quantity ?? 0;
            $hasStock = $available >= $op->quantity;
            
            if (!$hasStock) {
                $hasWarning = true;
            }

            $items[$op->product_id] = [
                'available' => $available,
                'has_stock' => $hasStock
            ];
        }

        return [
            'has_warning' => $hasWarning,
            'items' => $items
        ];
    }

    public function hasStock()
    {
        // Si ya se descontó el stock de esta orden, ya no necesitamos validar contra el inventario actual
        if ($this->isStockDeducted()) {
            return true;
        }

        return !$this->getStockDetails()['has_warning'];
    }

    /**
     * Verifica si ya se ha registrado un movimiento de salida de inventario para esta orden.
     * Calcula la diferencia entre salidas e ingresos vinculados a la orden.
     */
    public function isStockDeducted()
    {
        $out = \App\Models\InventoryMovement::where('reference_type', 'Order')
            ->where('reference_id', $this->id)
            ->where('movement_type', 'out')
            ->sum('quantity');

        $in = \App\Models\InventoryMovement::where('reference_type', 'Order')
            ->where('reference_id', $this->id)
            ->where('movement_type', 'in')
            ->sum('quantity');

        return ($out - $in) > 0;
    }

    /**
     * Automatically syncs the order status based on current stock levels.
     * Moves to 'Sin Stock' and deassigns agent if stock is missing.
     * Saves the previous status so it can be restored when stock is recovered.
     */
    public function syncStockStatus()
    {
        $excludedStatuses = [
            'Entregado', 
            'En ruta', 
            'Cancelado', 
            'Rechazado', 
            'Sin Stock', 
            'Novedades', 
            'Novedad Solucionada',
            'Asignar a agencia'
        ];
        
        // Use relation if loaded, otherwise fresh query
        $statusDesc = $this->status ? $this->status->description : Status::find($this->status_id)?->description;
        
        if (in_array($statusDesc, $excludedStatuses)) {
            return false;
        }

        // Ya NO usamos getStockDetails() directamente.
        // hasStock() incluye la validación de isStockDeducted() para no quitarle
        // el estatus a las órdenes que ya hicieron su reserva de bodega.
        if (!$this->hasStock()) {
            $sinStockStatus = Status::where('description', '=', 'Sin Stock')->first();
            if ($sinStockStatus && $this->status_id !== $sinStockStatus->id) {
                $oldStatusId = $this->status_id; // 💾 Guardar status anterior

                $this->previous_status_id = $oldStatusId; // 💾 Persistir para restaurar después
                $this->status_id = $sinStockStatus->id;
                // ✅ NO se desasigna agent_id: la vendedora permanece asignada
                $this->save();

                // Log activity
                OrderActivityLog::create([
                    'order_id' => $this->id,
                    'user_id'  => auth()->id() ?? 1,
                    'action'   => 'status_changed',
                    'description' => "Orden movida automáticamente a 'Sin Stock' por falta de existencias (Detectado en sincronización). Vendedora mantiene la asignación.",
                    'properties' => [
                        'old_status_id' => $oldStatusId,
                        'agent_id'      => $this->agent_id,
                        'reason'        => 'stock_shortage_sync'
                    ]
                ]);

                // Order Update note
                OrderUpdate::create([
                    'order_id' => $this->id,
                    'user_id'  => auth()->id() ?? User::whereHas('role', function($q){ $q->where('description', 'Admin'); })->first()?->id ?? 1,
                    'message'  => "🚨 AUTOMÁTICO: La orden pasó a 'Sin Stock' por falta de producto en almacén. La vendedora asignada se mantiene."
                ]);

                // 📡 Broadcast via WebSocket for real-time Kanban update
                $this->load(['status', 'client', 'agent', 'agency', 'deliverer']);
                event(new \App\Events\OrderUpdated($this));

                return true;
            }
        }

        return false;
    }

    public function activityLogs()
    {
        return $this->hasMany(OrderActivityLog::class);
    }
}
