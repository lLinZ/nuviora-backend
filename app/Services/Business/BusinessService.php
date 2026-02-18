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
        Setting::set('round_robin_pointer', null);

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
        try {
            $statusesToReset = [
                'Asignado a vendedor',
                'Llamado 1',
                'Llamado 2',
                'Llamado 3',
                'Esperando Ubicacion',
                'Programado para mas tarde',
                // Novedades y "Programado para otro dia" se manejan por separado
            ];
            
            $statusIds = Status::whereIn('description', $statusesToReset)->pluck('id');
            $nuevoId = Status::where('description', 'Nuevo')->value('id');

            if ($statusIds->isNotEmpty() && $nuevoId) {
                // 1. Resetear órdenes normales a "Nuevo"
                Order::where('shop_id', $shopId)
                    ->whereIn('status_id', $statusIds)
                    ->update([
                        'agent_id' => null,
                        'status_id' => $nuevoId
                    ]);

                // 2. 🔥 CLIENT REQUEST: Novedades y Novedad Solucionada NO deben desasignarse
                // El cliente pidió explícitamente que no se quite el vendedor ni desaparezcan.
                /*
                $novedadStatus = Status::where('description', 'Novedades')->first();
                if ($novedadStatus) {
                    Order::where('shop_id', $shopId)
                        ->where('status_id', $novedadStatus->id)
                        ->update(['agent_id' => null]);
                }
                */
                
                // 3. Para "Programado para otro dia" y "Reprogramado": Solo quitar vendedor, mantener status
                $statusesToKeep = ['Programado para otro dia', 'Reprogramado', 'Reprogramado para hoy'];
                $keepIds = Status::whereIn('description', $statusesToKeep)->pluck('id');

                if ($keepIds->isNotEmpty()) {
                    Order::where('shop_id', $shopId)
                        ->whereIn('status_id', $keepIds)
                        ->update(['agent_id' => null]);
                }
                
                Log::info("Shop $shopId closed. Orders reset logic applied. Rescheduled orders kept their status.");
            }
        } catch (\Exception $e) {
            Log::error("Error resetting orders on shop close: " . $e->getMessage());
        }
    }

    protected function processScheduledOrders(int $shopId): void
    {
        try {
            $scheduledStatus = Status::where('description', 'Programado para otro dia')->first();
            $reprogrammedTodayStatus = Status::firstOrCreate(['description' => 'Reprogramado para hoy']);
            
            if ($scheduledStatus && $reprogrammedTodayStatus) {
                $today = now()->toDateString();
                
                $updatedCount = Order::where('shop_id', $shopId)
                    ->where('status_id', $scheduledStatus->id)
                    ->whereDate('scheduled_for', '<=', $today)
                    ->update([
                        'status_id' => $reprogrammedTodayStatus->id,
                        'agent_id'  => null // Asegurar que no tienen agente para que el backlog los tome
                    ]);
                    
                if ($updatedCount > 0) {
                    Log::info("BusinessService: Updated {$updatedCount} orders from 'Programado para otro dia' to 'Reprogramado para hoy' for shop {$shopId}.");
                }
            }
        } catch (\Exception $e) {
            Log::error("BusinessService: Error processing scheduled orders: " . $e->getMessage());
        }
    }
}
