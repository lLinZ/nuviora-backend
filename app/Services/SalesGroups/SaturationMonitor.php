<?php

namespace App\Services\SalesGroups;

use App\Constants\OrderStatus;
use App\Models\BusinessDay;
use App\Models\DailyAgentRoster;
use App\Models\Order;
use App\Models\SalesGroup;
use App\Models\SalesGroupMember;
use App\Models\SaturationAlert;
use App\Models\Status;
use App\Models\User;
use App\Notifications\SellerSaturatedNotification;
use Illuminate\Support\Collection;

/**
 * Alerta de saturación (spec de la Líder §9), la única alerta en tiempo real de esta versión.
 *
 * - Carga activa = pedidos en "Asignado a vendedor" y "Reprogramado para hoy" (§9.1).
 * - Se compara con el promedio de las integrantes del grupo que trabajan hoy (en el roster de alguna
 *   tienda), la Líder incluida si trabaja. Hacen falta al menos dos para comparar.
 * - Alerta si carga ≥ promedio × 1,30 (§9.2) y, para no avisar por diferencias mínimas, si tiene al
 *   menos MIN_LOAD pedidos en carga (decisión por defecto, HANDOFF §7.1).
 * - Avisa una vez: vuelve a avisar solo si baja del umbral y lo vuelve a pasar.
 */
final class SaturationMonitor
{
    public const LOAD_STATUSES = [OrderStatus::ASIGNADO_VENDEDOR, OrderStatus::REPROGRAMADO_HOY];
    public const THRESHOLD = 1.30;
    public const MIN_LOAD = 3;

    /**
     * Carga y estado de cada integrante del grupo.
     *
     * @return array{average: ?float, members: array<int, array{load: int, working: bool, saturated: bool, over_pct: ?float}>}
     */
    public function groupStatus(SalesGroup $group): array
    {
        $ids = $group->openMembers()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $loads = $this->loads($ids);
        $working = DailyAgentRoster::where('date', now()->toDateString())
            ->where('is_active', true)
            ->whereIn('agent_id', $ids)
            ->pluck('agent_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $comparable = array_values(array_intersect($ids, $working));
        $average = count($comparable) >= 2
            ? array_sum(array_map(fn ($id) => $loads[$id] ?? 0, $comparable)) / count($comparable)
            : null;

        $members = [];
        foreach ($ids as $id) {
            $load = $loads[$id] ?? 0;
            $isWorking = in_array($id, $working, true);
            $saturated = $average !== null && $average > 0 && $isWorking
                && $load >= self::MIN_LOAD && $load >= $average * self::THRESHOLD;
            $members[$id] = [
                'load' => $load,
                'working' => $isWorking,
                'saturated' => $saturated,
                'over_pct' => $average ? round(($load / $average - 1) * 100, 1) : null,
            ];
        }

        return ['average' => $average === null ? null : round($average, 2), 'members' => $members];
    }

    /**
     * Revisa todos los grupos: abre alertas nuevas (y avisa) y cierra las que ya bajaron.
     *
     * @return array{opened: int, cleared: int}
     */
    public function check(): array
    {
        $opened = $cleared = 0;
        if (!BusinessDay::where('date', now()->toDateString())->whereNotNull('open_at')->whereNull('close_at')->exists()) {
            // Fuera de jornada no se avisa; las alertas abiertas se cierran para empezar limpio mañana.
            return ['opened' => 0, 'cleared' => SaturationAlert::open()->update(['cleared_at' => now()])];
        }

        foreach (SalesGroup::active()->get() as $group) {
            $status = $this->groupStatus($group);
            $open = SaturationAlert::open()->where('sales_group_id', $group->id)->get()->keyBy('seller_id');

            foreach ($status['members'] as $sellerId => $m) {
                if ($m['saturated'] && !$open->has($sellerId)) {
                    $alert = SaturationAlert::create([
                        'seller_id' => $sellerId,
                        'sales_group_id' => $group->id,
                        'load' => $m['load'],
                        'group_average' => $status['average'],
                        'over_pct' => $m['over_pct'],
                    ]);
                    $this->notify($group, $alert);
                    $opened++;
                } elseif (!$m['saturated'] && $open->has($sellerId)) {
                    $open[$sellerId]->update(['cleared_at' => now()]);
                    $cleared++;
                }
            }

            // Si alguien dejó el grupo con una alerta abierta, se cierra.
            foreach ($open as $sellerId => $alert) {
                if (!array_key_exists($sellerId, $status['members'])) {
                    $alert->update(['cleared_at' => now()]);
                    $cleared++;
                }
            }
        }

        return ['opened' => $opened, 'cleared' => $cleared];
    }

    /** @return array<int, int> user_id => carga activa */
    private function loads(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $statusIds = Status::whereIn('description', self::LOAD_STATUSES)->pluck('id');

        return Order::whereIn('agent_id', $ids)
            ->whereIn('status_id', $statusIds)
            ->groupBy('agent_id')
            ->selectRaw('agent_id, COUNT(*) as c')
            ->pluck('c', 'agent_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    private function notify(SalesGroup $group, SaturationAlert $alert): void
    {
        $seller = User::find($alert->seller_id);
        $name = trim(($seller?->names ?? '') . ' ' . ($seller?->surnames ?? ''));
        $average = rtrim(rtrim(number_format($alert->group_average, 1, ',', '.'), '0'), ',');
        $message = "{$name} está saturada: tiene {$alert->load} pedidos en carga, "
            . number_format($alert->over_pct, 0) . " % más que el promedio de {$group->name} ({$average}).";

        $leaderId = $group->openMembers()->where('role', SalesGroupMember::ROLE_LEADER)->value('user_id');
        $recipients = $this->admins();
        if ($leaderId) {
            $leader = User::find($leaderId);
            $leader?->notify(new SellerSaturatedNotification($alert, $message, '/mi-grupo'));
            $recipients = $recipients->reject(fn ($u) => $u->id === $leaderId);
        }
        foreach ($recipients as $admin) {
            $admin->notify(new SellerSaturatedNotification($alert, $message, '/round-robin'));
        }
    }

    private function admins(): Collection
    {
        return User::whereHas('role', fn ($q) => $q->whereIn('description', ['Admin', 'Master']))->get();
    }
}
