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
        'scheduled_for'          => 'date',
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
        'novedad_resolution',
        'change_rate',
        'is_return',
        'is_exchange',
        'parent_order_id',
        // 'change_payment_details', // Moved to extra table
        // 'change_receipt', // Moved to extra table
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
    protected $appends = ['ves_price', 'bcv_equivalence', 'change_payment_details', 'change_receipt'];

    public function getChangePaymentDetailsAttribute()
    {
        return $this->changeExtra ? $this->changeExtra->change_payment_details : null;
    }

    public function getChangeReceiptAttribute()
    {
        return $this->changeExtra ? $this->changeExtra->change_receipt : null;
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
        return !$this->getStockDetails()['has_warning'];
    }

    /**
     * Automatically syncs the order status based on current stock levels.
     * Moves to 'Sin Stock' and deassigns agent if stock is missing.
     */
    public function syncStockStatus()
    {
        $excludedStatuses = ['Entregado', 'En ruta', 'Cancelado', 'Rechazado', 'Sin Stock', 'Novedades', 'Novedad Solucionada'];
        
        // Use relation if loaded, otherwise fresh query
        $statusDesc = $this->status ? $this->status->description : Status::find($this->status_id)?->description;
        
        if (in_array($statusDesc, $excludedStatuses)) {
            return false;
        }

        $stockDetails = $this->getStockDetails();
        
        if ($stockDetails['has_warning']) {
            $sinStockStatus = Status::where('description', '=', 'Sin Stock')->first();
            if ($sinStockStatus && $this->status_id !== $sinStockStatus->id) {
                $oldAgentId = $this->agent_id;
                $this->status_id = $sinStockStatus->id;
                $this->agent_id = null;
                $this->save();

                // Log activity
                OrderActivityLog::create([
                    'order_id' => $this->id,
                    'user_id' => auth()->id() ?? 1,
                    'action' => 'status_changed',
                    'description' => "Orden movida automáticamente a 'Sin Stock' por falta de existencias (Detectado en sincronización).",
                    'properties' => [
                        'old_agent_id' => $oldAgentId,
                        'reason' => 'stock_shortage_sync'
                    ]
                ]);

                // Order Update note
                OrderUpdate::create([
                    'order_id' => $this->id,
                    'user_id' => auth()->id() ?? User::whereHas('role', function($q){ $q->where('description', 'Admin'); })->first()?->id ?? 1,
                    'message' => "🚨 AUTOMÁTICO: La orden pasó a 'Sin Stock' debido a falta de producto en almacén."
                ]);

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
