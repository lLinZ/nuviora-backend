<?php
// app/Services/Assignment/AssignOrderService.php
namespace App\Services\Assignment;

use App\Models\DailyAgentRoster;
use App\Models\Order;
use App\Models\OrderAssignmentLog;
use App\Models\Setting;
use App\Models\Status;
use App\Models\BusinessDay;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Constants\OrderStatus;

class AssignOrderService
{
    protected AssignmentStrategy $strategy;

    public function __construct()
    {
        $mode = Setting::get('assignment_strategy', 'round_robin');
        $this->strategy = $mode === 'load_balanced'
            ? new LoadBalancedStrategy()
            : new RoundRobinStrategy();
    }

    /**
     * Asigna una orden (si hay roster y dentro de horario).
     * @throws \Throwable
     */
    public function assignOne(Order $order): ?User
    {
        if ($order->agent_id) return $order->agent;

        // si quieres bloquear fuera de jornada:
        if (!$this->isBusinessOpen($order->shop_id)) {
            return null; // fuera de jornada, no auto-asigna
        }

        // 🛑 VALIDACIÓN DE STOCK: No asignar si no hay existencias.
        // Segunda línea de defensa (la primera es el webhook).
        // Cargamos los productos si no están cargados aún.
        if (!$order->relationLoaded('products')) {
            $order->load('products');
        }
        $stockCheck = $order->getStockDetails();
        if ($stockCheck['has_warning']) {
            $sinStockStatus = Status::where('description', OrderStatus::SIN_STOCK)->first();
            if ($sinStockStatus && $order->status_id !== $sinStockStatus->id) {
                $order->status_id = $sinStockStatus->id;
                $order->save();
                event(new \App\Events\OrderUpdated($order));
                \Illuminate\Support\Facades\Log::warning("AssignOrderService: Orden #{$order->name} sin stock. No se asigna a ninguna vendedora.");
            }
            return null; // ⛔ No asignar
        }

        $agents = $this->activeAgentsForDate(now()->toDateString(), $order->shop_id);
        if ($agents->isEmpty()) return null;

        return DB::transaction(function () use ($order, $agents) {
            $ord = Order::where('id', '=', $order->id)->lockForUpdate()->first(['*']);
            if ($ord->agent_id) return $ord->agent;

            $agentId = $this->strategy->pickAgentId($agents, $ord);

            // Buscar status "Asignado a Vendedor"
            $statusAsignado = Status::where('description', OrderStatus::ASIGNADO_VENDEDOR)->first();
            $statusId = $statusAsignado ? $statusAsignado->id : $ord->status_id;

            $ord->update(['agent_id' => $agentId, 'status_id' => $statusId]);

            // 📡 Broadcast for real-time updates
            event(new \App\Events\OrderUpdated($ord));

            \App\Models\OrderAssignmentLog::create([
                'order_id'    => $ord->id,
                'agent_id'    => $agentId,
                'strategy'    => (new \ReflectionClass($this->strategy))->getShortName(),
                'assigned_by' => null, // sistema
                'meta'        => ['reason' => 'auto'],
            ]);

            // 🔔 Notificar al agente asignado
            try {
                // Importar o usar namespace completo
                $ord->agent->notify(new \App\Notifications\OrderAssignedNotification($ord, "Nueva orden asignada: #{$ord->name}"));
            } catch (\Exception $e) {
                // Ignorar error de notificación
            }

            return $ord->agent;
        });
    }


    /**
     * Asigna todas las órdenes sin agente en estados asignables.
     * El parámetro $from/$to se mantiene por compatibilidad pero ya NO filtra por fecha:
     * el bug original era que órdenes en "Nuevo" de días anteriores nunca eran encontradas
     * porque su updated_at/created_at estaba fuera del rango.
     */
    public function assignBacklog(\DateTimeInterface $from, \DateTimeInterface $to, ?int $shopId = null): int
    {
        $date = $to->format('Y-m-d');
        
        // Statuses que NUNCA deben ser asignados automáticamente
        $excludedStatuses = [
            OrderStatus::SIN_STOCK,
            OrderStatus::ENTREGADO,
            OrderStatus::CANCELADO,
            OrderStatus::RECHAZADO,
            OrderStatus::EN_RUTA,
            OrderStatus::ASIGNAR_A_AGENCIA,
            OrderStatus::POR_APROBAR_UBICACION,
            OrderStatus::POR_APROBAR_RECHAZO,
        ];
        $excludedIds = Status::whereIn('description', $excludedStatuses)->pluck('id');

        // 🔥 FIX: Sin filtro de fecha. Buscamos TODAS las órdenes sin agente
        // en estados asignables, independientemente de cuándo fueron creadas.
        // Esto captura órdenes que llevan días en "Nuevo" sin asignar.
        $query = Order::query()
            ->whereNull('agent_id')
            ->whereNotIn('status_id', $excludedIds);

        // 🛡️ Filtro por Tienda (Aislamiento)
        if ($shopId) {
            $query->where('shop_id', $shopId);
        }

        \Illuminate\Support\Facades\Log::info("AssignBacklog: Buscando órdenes sin agente para shop_id={$shopId}. Rango referencial: {$from->format('Y-m-d H:i')} → {$to->format('Y-m-d H:i')}");

        $ids = $query->get(['*']);

        $assignmentStatus = Status::firstOrCreate(['description' => OrderStatus::ASIGNADO_VENDEDOR]);
        $assignmentStatusId = (int)$assignmentStatus->id;
        
        $novedadStatus = Status::where('description', OrderStatus::NOVEDADES)->first();
        $novedadStatusId = $novedadStatus ? (int)$novedadStatus->id : null;

        $count = 0;
        foreach ($ids as $ordModel) {
            /** @var Order $ordModel */
            // 🛑 CHECK STOCK: Only assign if order HAS stock
            if (!$ordModel->hasStock()) {
                continue;
            }

            // 🛡️ Ensure we look for agents in the SPECIFIC shop of the order, or forced shopId
            $targetShopId = $shopId ?: $ordModel->shop_id;
            
            if (!$targetShopId) {
                // If order has no shop and no shopId passed, we can't safely assign from a specific roster.
                // Depending on business logic, skipping is safer than assigning random agents.
                continue; 
            }

            // 🛑 CHECK BUSINESS HOURS: Solo asignar si la tienda está abierta en este momento.
            if (!$this->isBusinessOpen($targetShopId)) {
                continue;
            }

            $agentsForShop = $this->activeAgentsForDate($date, $targetShopId);
            if ($agentsForShop->isEmpty()) continue;

            DB::transaction(function () use ($ordModel, $agentsForShop, &$count, $assignmentStatusId, $novedadStatusId) {
                $ord = Order::where('id', '=', $ordModel->id)->lockForUpdate()->first(['*']);
                
                // Safety check: skip if already assigned by someone else
                if ($ord->agent_id && $ord->status_id !== $ordModel->status_id) return;
                // If it was "Sin Stock" but now has an agent, skip
                if ($ord->agent_id && $ordModel->status_id === $assignmentStatusId) return;

                $agentId = $this->strategy->pickAgentId($agentsForShop, $ord);
                
                // 🛡️ SAFETY CHECK: Si no hay agente, no cambiamos el status a "Asignado..."
                if (!$agentId) {
                    \Illuminate\Support\Facades\Log::warning("AssignBacklog: pickAgentId returned null/empty for Order #{$ord->id}. Skipping update.");
                    return;
                }

                // Lógica de Status Inteligente:
                // Si ya era Novedad, mantenemos Novedad.
                // Si venía de "Programado para otro dia", ahora es "Reprogramado para hoy".
                // De lo contrario, "Asignado a Vendedor".
                
                // 🔥 CLIENT FIX: Preservar status "Programado para otro dia"
                // Para que aparezcan en la columna "Reprogramado para hoy" (que filtra por ese status + fecha hoy),
                // NO debemos cambiarles el status a "Asignado a Vendedor" ni a "Reprogramado para hoy".

                $currentStatusDesc = $ordModel->status ? $ordModel->status->description : '';

                if ($currentStatusDesc === OrderStatus::PROGRAMADO_OTRO_DIA || $currentStatusDesc === OrderStatus::REPROGRAMADO_HOY) {
                    // 🛡️ Filtro estricto: Solo asignar si es para hoy o pasado. 
                    $scheduledDate = $ordModel->scheduled_for ? $ordModel->scheduled_for->toDateString() : null;
                    $today = now()->toDateString();
                    
                    if ($scheduledDate && $scheduledDate > $today) {
                        return; // Futuro: Permitir que permanezca en el backlog sin agente
                    }

                    if ($currentStatusDesc === OrderStatus::PROGRAMADO_OTRO_DIA) {
                        $reproToday = Status::where('description', OrderStatus::REPROGRAMADO_HOY)->first();
                        $newStatusId = $reproToday ? $reproToday->id : $ordModel->status_id;
                    } else {
                        $newStatusId = $ordModel->status_id;
                    }
                } elseif ($novedadStatusId && $ordModel->status_id === $novedadStatusId) {
                    $newStatusId = $novedadStatusId;
                } else {
                    $newStatusId = $assignmentStatusId;
                }
                
                $ord->update(['agent_id' => $agentId, 'status_id' => $newStatusId]);

                // 📡 Broadcast for real-time updates
                event(new \App\Events\OrderUpdated($ord));

                OrderAssignmentLog::create([
                    'order_id'    => $ord->id,
                    'agent_id'    => $agentId,
                    'strategy'    => (new \ReflectionClass($this->strategy))->getShortName(),
                    'assigned_by' => Auth::id(), // lo dispara la gerente desde la UI
                    'meta'        => ['reason' => 'backlog'],
                ]);

                // 🤫 SILENCED: No individual notification during mass backlog processing to avoid spam.
                // $agent = \App\Models\User::find($agentId);
                // if ($agent) {
                //     $agent->notify(new \App\Notifications\OrderAssignedNotification($ord, "Se te ha asignado la orden #{$ord->name}"));
                // }

                $count++;
            });
        }

        return $count;
    }

    /** Helpers */

    protected function activeAgentsForDate(string $date, ?int $shopId = null): Collection
    {
        $query = DailyAgentRoster::with('agent')
            ->where('date', $date)
            ->where('is_active', true);

        if ($shopId) {
            $query->where('shop_id', $shopId);
        } else {
            $openShopIds = BusinessDay::where('date', $date)
                ->whereNotNull('open_at')
                ->whereNull('close_at')
                ->pluck('shop_id');
            $query->whereIn('shop_id', $openShopIds);
        }

        $rows = $query->get();

        return $rows->pluck('agent')->filter();
    }

    protected function isWithinWindow($now, string $open, string $close): bool
    {
        // Soporta casos "normales" (09:00–18:00) y "nocturnos" (20:00–06:00)
        $start = $now->copy()->setTimeFromTimeString($open);
        $end   = $now->copy()->setTimeFromTimeString($close);

        // Si cierra al día siguiente (overnight)
        if ($end->lessThanOrEqualTo($start)) {
            // Ventana: [start, 23:59:59] ∪ [00:00, end]
            return $now->greaterThanOrEqualTo($start) || $now->lessThanOrEqualTo($end);
        }

        return $now->betweenIncluded($start, $end);
    }


    public function isBusinessOpen(?int $shopId = null): bool
    {
        $query = BusinessDay::where('date', '=', now()->toDateString())
                            ->whereNotNull('open_at')
                            ->whereNull('close_at');
        if ($shopId) {
            $query->where('shop_id', '=', $shopId);
        }
        
        return $query->exists();
    }
}
