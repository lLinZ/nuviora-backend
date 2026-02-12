<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderTrackingComprehensiveLog extends Model
{
    protected $fillable = [
        'order_id',
        'from_status_id',
        'to_status_id',
        'seller_id',
        'user_id',
        'was_unassigned',
        'was_reassigned',
        'previous_seller_id',
        'description'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function fromStatus()
    {
        return $this->belongsTo(Status::class, 'from_status_id');
    }

    public function toStatus()
    {
        return $this->belongsTo(Status::class, 'to_status_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function previousSeller()
    {
        return $this->belongsTo(User::class, 'previous_seller_id');
    }

    public static function log(Order $order, $toStatusId, $userId = null, $description = null)
    {
        $fromStatusId = $order->getOriginal('status_id');
        $previousSellerId = $order->getOriginal('agent_id');
        $currentSellerId = $order->agent_id;
        
        $wasUnassigned = $previousSellerId && !$currentSellerId;
        $wasReassigned = $previousSellerId && $currentSellerId && $previousSellerId != $currentSellerId;

        // Generación 100% automática para que las vendedoras no tengan que hacer nada
        if (!$description) {
            $parts = [];

            // 1. Detectar cambio de status
            if ($fromStatusId != $toStatusId) {
                $fromStatus = Status::find($fromStatusId)?->description ?? 'Inicio';
                $toStatus = Status::find($toStatusId)?->description ?? 'Desconocido';
                $parts[] = "Estado cambió de '{$fromStatus}' a '{$toStatus}'";
            }

            // 2. Detectar reasignación
            if ($wasReassigned) {
                $prev = User::find($previousSellerId);
                $curr = User::find($currentSellerId);
                $parts[] = "Reasignada de " . ($prev->name ?? 'Anterior') . " a " . ($curr->name ?? 'Nueva');
            } elseif ($wasUnassigned) {
                $prev = User::find($previousSellerId);
                $parts[] = "Desasignada de " . ($prev->name ?? 'Anterior');
            } elseif (!$previousSellerId && $currentSellerId) {
                $curr = User::find($currentSellerId);
                $parts[] = "Asignada a " . ($curr->name ?? 'Vendedora');
            }

            $description = implode(". ", $parts);
        }

        return self::create([
            'order_id' => $order->id,
            'from_status_id' => $fromStatusId,
            'to_status_id' => $toStatusId,
            'seller_id' => $currentSellerId,
            'user_id' => $userId ?? auth()->id(),
            'was_unassigned' => $wasUnassigned,
            'was_reassigned' => $wasReassigned,
            'previous_seller_id' => $previousSellerId,
            'description' => $description ?: "Movimiento registrado"
        ]);
    }
}
