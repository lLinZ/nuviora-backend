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
        // El reparto de la tienda arranca el día desde cero (sin arrastrar saldos de ayer).
        app(\App\Services\Assignment\WeightedAssigner::class)->reset(\App\Services\Assignment\WeightedAssigner::poolFor($shopId));

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

        // 🛑 Desactivar roster: Ningún agente debe quedar activo al cerrar la tienda.
        // Esto bloquea asignaciones de WhatsApp CRM y backlog fuera de horario.
        DailyAgentRoster::where('date', $today)
            ->where('shop_id', $shopId)
            ->update(['is_active' => false]);
        Log::info("Shop $shopId: Roster desactivado al cerrar la jornada.");

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
                    ->get(['id', 'reset_count', 'status_id', 'agent_id']);

                $toCancel = $ordersToProcess->where('reset_count', '>', 0);
                $toReset  = $ordersToProcess->where('reset_count', 0);

                // La cancelada conserva su vendedora: es suya y así cuenta en sus estadísticas
                // (antes quedaba "Sin Asignar" y sin rastro en el historial).
                if ($toCancel->isNotEmpty()) {
                    Order::whereIn('id', $toCancel->pluck('id'))->update([
                        'status_id'    => $canceladoId,
                        'cancelled_at' => now(),
                        'reset_count'  => 0,
                    ]);
                    $this->logClose($toCancel, fn ($o) => "Cancelada automáticamente al cierre de la jornada: era la segunda vez que llegaba al cierre sin resolverse (estaba en '{$this->statusName($o->status_id)}'" . $this->withSeller($o) . ').', 'closing_cancelled', $canceladoId);
                    Log::info("Shop $shopId: Canceladas {$toCancel->count()} órdenes por reincidencia.");
                }

                if ($toReset->isNotEmpty()) {
                    Order::whereIn('id', $toReset->pluck('id'))->update([
                        'agent_id'    => null,
                        'status_id'   => $nuevoId,
                        'reset_count' => 1,
                    ]);
                    $this->logClose($toReset, fn ($o) => "Volvió a 'Nuevo' sin vendedora al cierre de la jornada: no se resolvió hoy (estaba en '{$this->statusName($o->status_id)}'" . $this->withSeller($o) . '). Mañana se reparte de nuevo; si vuelve a llegar al cierre sin resolverse, se cancela.', 'closing_reset', $nuevoId);
                    Log::info("Shop $shopId: Reseteadas {$toReset->count()} órdenes a 'Nuevo' (primera vez).");
                }
            }

            // ─────────────────────────────────────────────────────────
            // 3. "Reprogramado para hoy" → mover a "Programado para otro dia" para mañana
            // ─────────────────────────────────────────────────────────
            if ($reprogramadoHoyId && $programmadoOtroDiaId) {
                $moved = Order::where('shop_id', $shopId)
                    ->where('status_id', $reprogramadoHoyId)
                    ->get(['id', 'status_id', 'agent_id']);
                if ($moved->isNotEmpty()) {
                    Order::whereIn('id', $moved->pluck('id'))->update([
                        'agent_id'      => null,
                        'status_id'     => $programmadoOtroDiaId,
                        'scheduled_for' => now()->addDay()->startOfDay()->addHours(9), // Fallback a las 9 AM
                    ]);
                    $this->logClose($moved, fn ($o) => "Pasó a mañana al cierre de la jornada: estaba en 'Reprogramado para hoy'" . $this->withSeller($o) . '. Mañana se reparte de nuevo.', 'closing_rescheduled', $programmadoOtroDiaId);
                }
                Log::info("Shop $shopId: Movidas órdenes 'Reprogramado para hoy' a mañana.");
            }

            // ─────────────────────────────────────────────────────────
            // 4. Estatus que mantienen su estado pero pierden el vendedor
            // ─────────────────────────────────────────────────────────
            $keepStatusNames = ['Programado para otro dia', 'Reprogramado'];
            $keepIds = Status::whereIn('description', $keepStatusNames)->pluck('id');

            if ($keepIds->isNotEmpty()) {
                // Solo las que todavía tenían vendedora: así el historial lo dice una vez, no cada noche
                $released = Order::where('shop_id', $shopId)->whereIn('status_id', $keepIds)->whereNotNull('agent_id')
                    ->get(['id', 'status_id', 'agent_id', 'scheduled_for']);
                if ($released->isNotEmpty()) {
                    Order::whereIn('id', $released->pluck('id'))->update(['agent_id' => null]);
                    $this->logClose($released, fn ($o) => 'Quedó sin vendedora al cierre de la jornada' . $this->withSeller($o, 'antes la tenía') . ' hasta el día programado' . ($o->scheduled_for ? ' (' . $o->scheduled_for->format('d/m') . ')' : '') . ': ese día se reparte de nuevo.', 'closing_released', null);
                }
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
                    // Un choque con otra escritura se reintenta; si igual falla, se sigue con las demás
                    try {
                        \App\Support\DbRetry::onConflict(fn () => $order->update([
                            'status_id' => $reprogrammedTodayStatus->id,
                            'agent_id'  => null // Asegurar que no tienen agente para que el backlog los tome
                        ]));
                        $updatedCount++;
                    } catch (\Throwable $e) {
                        Log::error("BusinessService: la orden #{$order->id} no pasó a 'Reprogramado para hoy': " . $e->getMessage());
                    }
                }
                    
                if ($updatedCount > 0) {
                    Log::info("BusinessService: Updated {$updatedCount} orders from 'Programado para otro dia' to 'Reprogramado para hoy' for shop {$shopId}.");
                }
            }
        } catch (\Exception $e) {
            Log::error("BusinessService: Error processing scheduled orders: " . $e->getMessage());
        }
    }

    /** @var array<int, string>|null */
    private ?array $statusNames = null;

    /** @var array<int, string> */
    private array $sellerNames = [];

    private function statusName(?int $statusId): string
    {
        $this->statusNames ??= Status::pluck('description', 'id')->all();

        return $this->statusNames[$statusId] ?? 'sin estado';
    }

    /** " con Gigi", o " (antes la tenía Gigi)", si la orden tenía vendedora. */
    private function withSeller(Order $order, string $prefix = 'con'): string
    {
        if (!$order->agent_id) {
            return '';
        }
        $name = $this->sellerNames[$order->agent_id] ??= \App\Models\User::whereKey($order->agent_id)->value('names') ?? "la vendedora #{$order->agent_id}";

        return $prefix === 'con' ? " con {$name}" : " ({$prefix} {$name})";
    }

    /**
     * Deja en el historial de cada orden lo que le hizo el cierre. Las actualizaciones del cierre son
     * masivas (no pasan por los observers), por eso antes no quedaba ningún rastro.
     */
    private function logClose(\Illuminate\Support\Collection $orders, callable $describe, string $action, ?int $newStatusId): void
    {
        try {
            $now = now();
            $rows = $orders->map(fn (Order $o) => [
                'order_id'    => $o->id,
                'user_id'     => null,
                'actor_role'  => 'Sistema',
                'action'      => $action,
                'description' => $describe($o),
                'properties'  => json_encode(array_filter([
                    'old_status' => $o->status_id,
                    'new_status' => $newStatusId,
                    'agent_id'   => $o->agent_id,
                ], fn ($v) => $v !== null)),
                'created_at'  => $now,
                'updated_at'  => $now,
            ])->values()->all();
            foreach (array_chunk($rows, 500) as $chunk) {
                \App\Models\OrderActivityLog::insert($chunk);
            }
        } catch (\Throwable $e) {
            Log::error("Cierre: no se pudo escribir el historial ({$action}): " . $e->getMessage());
        }
    }
}
