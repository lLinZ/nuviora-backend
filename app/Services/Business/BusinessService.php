<?php

namespace App\Services\Business;

use App\Models\BusinessDay;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\Status;
use App\Services\Assignment\AssignOrderService;
use App\Models\DailyAgentRoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BusinessService
{
    public function openShop(int $shopId, bool $assignBacklog = false, ?int $openedByUserId = null): array
    {
        $today = now()->toDateString();
        $now = now();

        // 1. Update/Create BusinessDay
        try {
            $day = BusinessDay::firstOrCreate(['date' => $today, 'shop_id' => $shopId]);
        } catch (\Illuminate\Database\QueryException $e) {
            $day = BusinessDay::where('date', $today)->where('shop_id', $shopId)->first();
            if (!$day) throw $e;
        }

        if ($day->open_at) {
            throw new \Exception("La jornada de esta tienda ya fue abierta.", 409);
        }

        $day->update([
            'open_at'   => $now,
            'opened_by' => $openedByUserId,
        ]);

        // 2. Legacy Settings (Global) - Update to keep legacy systems working
        Setting::set('business_is_open', true);
        Setting::set('business_open_dt', $now->toDateTimeString());
        Setting::set('business_last_open_dt', $now->toDateTimeString());
        Setting::set('round_robin_pointer_' . $shopId, null);

        // 3. 🔥 CLIENT REQUEST: Detectar órdenes programadas para hoy
        $this->processScheduledOrders($shopId);

        $assigned = 0;
        if ($assignBacklog) {
            // 🔥 FIX: Usar el inicio del día actual como límite inferior.
            // El 'business_last_close_dt' puede ser de ayer, pero las órdenes que llegaron
            // de madrugada tienen updated_at/created_at de HOY, por lo que no caían en el rango.
            // Tomamos el mínimo entre el último cierre y el inicio del día para no perder ninguna.
            $lastClose = Setting::get('business_last_close_dt');
            $startOfDay = now()->startOfDay();
            
            if ($lastClose) {
                $lastCloseDate = now()->parse($lastClose);
                // Usar el más antiguo de los dos para capturar todo
                $from = $lastCloseDate->lessThan($startOfDay) ? $lastCloseDate : $startOfDay;
            } else {
                $from = $startOfDay;
            }
            
            $to = now();
            $assigned = app(AssignOrderService::class)->assignBacklog($from, $to, $shopId);
        }

        return [
            'open_dt'  => $now->toDateTimeString(),
            'open_at'  => $day->open_at->toDateTimeString(),
            'assigned' => $assigned,
            'is_open'  => true
        ];
    }

    public function closeShop(int $shopId, ?int $closedByUserId = null): array
    {
        $today = now()->toDateString();
        $now = now();

        $day = BusinessDay::where('date', $today)->where('shop_id', $shopId)->first();

        if (!$day || !$day->open_at) {
            throw new \Exception("No puedes cerrar sin haber abierto la jornada.", 422);
        }

        if ($day->close_at) {
            throw new \Exception("La jornada ya fue cerrada.", 409);
        }

        $day->update([
            'close_at'  => $now,
            'closed_by' => $closedByUserId,
        ]);

        // Legacy Settings
        Setting::set('business_is_open', false);
        Setting::set('business_close_dt', $now->toDateTimeString());
        Setting::set('business_last_close_dt', $now->toDateTimeString());

        // Reset Orders Logic
        $this->resetOrdersForShop($shopId);

        return [
            'close_dt' => $now->toDateTimeString(),
            'close_at' => $day->close_at->toDateTimeString(),
            'is_open'  => false
        ];
    }

    public function activateDefaultRoster(int $shopId): void
    {
        $shop = Shop::with(['sellers' => function($q) {
            $q->wherePivot('is_default_roster', true);
        }])->find($shopId);

        if (!$shop) return;

        $agents = $shop->sellers;
        $today = now()->toDateString();

        foreach ($agents as $agent) {
            DailyAgentRoster::updateOrCreate(
                [
                    'date'     => $today,
                    'shop_id'  => $shopId,
                    'agent_id' => $agent->id,
                ],
                [
                    'is_active' => true
                ]
            );
        }
        
        Log::info("Activated default roster for Shop {$shopId}: {$agents->count()} agents.");
    }

    protected function resetOrdersForShop(int $shopId): void
    {
        // 🔇 Silenciar webhooks de n8n durante este proceso de reseteo nocturno
        \App\Observers\OrderObserver::$muteWebhooks = true;

        try {
            // ─────────────────────────────────────────────────────────
            // 1. STATUS IDs que necesitamos
            // ─────────────────────────────────────────────────────────
            $nuevoId               = Status::where('description', 'Nuevo')->value('id');
            $canceladoId           = Status::where('description', 'Cancelado')->value('id');
            $asignadoId            = Status::where('description', 'Asignado a vendedor')->value('id');
            $reprogramadoHoyId     = Status::where('description', 'Reprogramado para hoy')->value('id');
            $programmadoOtroDiaId  = Status::where('description', 'Programado para otro dia')->value('id');

            if (!$nuevoId || !$canceladoId) {
                Log::error("Shop $shopId: No se encontraron los statuses 'Nuevo' o 'Cancelado'. Abortando resetOrdersForShop.");
                return;
            }

            // ─────────────────────────────────────────────────────────
            // 2. "Asignado a vendedor" y "Llamados" (Lógica de Reset/Cancelación)
            // ─────────────────────────────────────────────────────────
            $statusesToReset = [
                'Asignado a vendedor',
                'Llamado 1',
                'Llamado 2',
                'Llamado 3',
                'Esperando Ubicacion',
                'Programado para mas tarde',
            ];
            $resetStatusIds = Status::whereIn('description', $statusesToReset)->pluck('id');

            if ($resetStatusIds->isNotEmpty()) {
                // Obtenemos órdenes para separar las que se cancelan de las que se resetean
                $ordersToProcess = Order::where('shop_id', $shopId)
                    ->whereIn('status_id', $resetStatusIds)
                    ->get(['id', 'reset_count']);

                $toCancel = $ordersToProcess->where('reset_count', '>', 0)->pluck('id');
                $toReset  = $ordersToProcess->where('reset_count', 0)->pluck('id');

                if ($toCancel->isNotEmpty()) {
                    Order::whereIn('id', $toCancel)->update([
                        'agent_id'     => null,
                        'status_id'    => $canceladoId,
                        'cancelled_at' => now(),
                        'reset_count'  => 0,
                    ]);
                    Log::info("Shop $shopId: Canceladas {$toCancel->count()} órdenes por reincidencia.");
                }

                if ($toReset->isNotEmpty()) {
                    Order::whereIn('id', $toReset)->update([
                        'agent_id'    => null,
                        'status_id'   => $nuevoId,
                        'reset_count' => 1,
                    ]);
                    Log::info("Shop $shopId: Reseteadas {$toReset->count()} órdenes a 'Nuevo' (primera vez).");
                }
            }

            // ─────────────────────────────────────────────────────────
            // 3. "Reprogramado para hoy" → mover a "Programado para otro dia" para mañana
            // ─────────────────────────────────────────────────────────
            if ($reprogramadoHoyId && $programmadoOtroDiaId) {
                // Para este caso específico usamos un pequeño bucle o bulk si es posible.
                // Dado que scheduled_for debe ser relativo, lo haremos eficiente.
                Order::where('shop_id', $shopId)
                    ->where('status_id', $reprogramadoHoyId)
                    ->update([
                        'agent_id'      => null,
                        'status_id'     => $programmadoOtroDiaId,
                        'scheduled_for' => now()->addDay()->startOfDay()->addHours(9), // Fallback a las 9 AM
                    ]);
                Log::info("Shop $shopId: Movidas órdenes 'Reprogramado para hoy' a mañana.");
            }

            // ─────────────────────────────────────────────────────────
            // 4. Estatus que mantienen su estado pero pierden el vendedor
            // ─────────────────────────────────────────────────────────
            $keepStatusNames = ['Programado para otro dia', 'Reprogramado'];
            $keepIds = Status::whereIn('description', $keepStatusNames)->pluck('id');

            if ($keepIds->isNotEmpty()) {
                Order::where('shop_id', $shopId)->whereIn('status_id', $keepIds)->update(['agent_id' => null]);
                Log::info("Shop $shopId closed. Cleared agent from 'Programado para otro dia' / 'Reprogramado' orders.");
            }

            Log::info("Shop $shopId: resetOrdersForShop completed successfully.");

        } catch (\Exception $e) {
            Log::error("Error resetting orders on shop close (shop $shopId): " . $e->getMessage() . "\n" . $e->getTraceAsString());
        } finally {
            // 🔊 Reactivar webhooks para el funcionamiento normal
            \App\Observers\OrderObserver::$muteWebhooks = false;
        }
    }

    protected function processScheduledOrders(int $shopId): void
    {
        try {
            $scheduledStatus = Status::where('description', 'Programado para otro dia')->first();
            $reprogrammedTodayStatus = Status::firstOrCreate(['description' => 'Reprogramado para hoy']);
            
            if ($scheduledStatus && $reprogrammedTodayStatus) {
                $today = now()->toDateString();
                
                $ordersToUpdate = Order::where('shop_id', $shopId)
                    ->where('status_id', $scheduledStatus->id)
                    ->whereDate('scheduled_for', '<=', $today)
                    ->get();
                    
                $updatedCount = 0;
                foreach ($ordersToUpdate as $order) {
                    $order->update([
                        'status_id' => $reprogrammedTodayStatus->id,
                        'agent_id'  => null // Asegurar que no tienen agente para que el backlog los tome
                    ]);
                    $updatedCount++;
                }
                    
                if ($updatedCount > 0) {
                    Log::info("BusinessService: Updated {$updatedCount} orders from 'Programado para otro dia' to 'Reprogramado para hoy' for shop {$shopId}.");
                }
            }
        } catch (\Exception $e) {
            Log::error("BusinessService: Error processing scheduled orders: " . $e->getMessage());
        }
    }
}
