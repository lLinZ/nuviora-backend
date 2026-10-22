<?php

namespace App\Services\Logistics;

use App\Models\LogisticsAlert;
use App\Models\LogisticsCenter;

/**
 * Las alertas de la torre de control (§21, §25). Cada una tiene una clave: mientras está abierta no se repite, y se
 * cierra sola cuando deja de aplicar (por ejemplo, SIN OPERADOR al iniciar el turno).
 */
class Alerts
{
    public function raise(LogisticsCenter $center, string $type, string $level, string $message, string $key,
        ?int $orderId = null, ?int $riderId = null, array $data = []): LogisticsAlert
    {
        $open = LogisticsAlert::where('dedupe_key', $key)->whereNull('resolved_at')->first();
        if ($open) {
            return $open;
        }

        return LogisticsAlert::create([
            'center_id' => $center->id,
            'order_id' => $orderId,
            'rider_id' => $riderId,
            'type' => $type,
            'level' => $level,
            'message' => $message,
            'data' => $data ?: null,
            'dedupe_key' => $key,
        ]);
    }

    /** Cierra las alertas abiertas con esa clave (o que empiezan con ella, si termina en "*"). */
    public function resolve(string $key, ?int $userId = null): int
    {
        $query = LogisticsAlert::whereNull('resolved_at');
        str_ends_with($key, '*') ? $query->where('dedupe_key', 'like', rtrim($key, '*') . '%') : $query->where('dedupe_key', $key);

        return $query->update(['resolved_at' => now(), 'resolved_by' => $userId]);
    }
}
