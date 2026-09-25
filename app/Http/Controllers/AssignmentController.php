<?php

// app/Http/Controllers/AssignmentController.php
namespace App\Http\Controllers;

use App\Models\DailyAgentRoster;
use App\Models\Order;
use App\Models\OrderAssignmentLog;
use App\Models\Role;
use App\Models\SalesGroupMember;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\Status;
use App\Models\User;
use App\Services\Assignment\AssignOrderService;
use App\Services\Assignment\BulkReassignService;
use App\Services\Assignment\WeightedAssigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssignmentController extends Controller
{
    protected function ensureManager(): void
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Gerente', 'Admin'])) abort(403, 'No autorizado');
    }

    public function assignBacklog(Request $request)
    {
        $this->ensureManager();

        $from = $request->filled('from') ? now()->parse($request->from) : now()->subDays(30)->startOfDay();
        $to   = $request->filled('to')   ? now()->parse($request->to)   : now();
        $shopId = $request->input('shop_id');

        $count = app(AssignOrderService::class)->assignBacklog($from, $to, $shopId);

        return response()->json([
            'status' => true,
            'message' => "Backlog asignado: {$count} órdenes",
            'data'   => ['assigned' => $count],
        ]);
    }

    /**
     * GET /assignment/overview — el reparto de hoy por tienda: quién está en el roster, su grupo,
     * cuánto debería recibir (con los % y máximos de ahora) y cuánto lleva recibido.
     */
    public function overview(WeightedAssigner $assigner)
    {
        $today = now()->toDateString();
        $rosters = DailyAgentRoster::with('agent:id,names,surnames,max_active_orders', 'agent.salesGroupMembership.group:id,name,is_active')
            ->where('date', $today)
            ->where('is_active', true)
            ->get()
            ->groupBy('shop_id');

        $allIds = $rosters->flatten()->pluck('agent_id')->unique()->values()->all();
        $active = $assigner->activeCounts($allIds);
        $received = OrderAssignmentLog::query()
            ->join('orders', 'orders.id', '=', 'order_assignment_logs.order_id')
            ->where('order_assignment_logs.created_at', '>=', now()->startOfDay())
            ->where('order_assignment_logs.strategy', 'like', WeightedAssigner::STRATEGY . '%')
            ->groupBy('orders.shop_id', 'order_assignment_logs.agent_id')
            ->selectRaw('orders.shop_id, order_assignment_logs.agent_id, COUNT(*) as c')
            ->get()
            ->groupBy('shop_id');

        $shops = Shop::orderBy('id')->get()->map(function (Shop $shop) use ($rosters, $assigner, $active, $received) {
            $agents = $rosters->get($shop->id, collect())->pluck('agent')->filter()->unique('id')->values();
            $targets = $assigner->targetShares($agents->pluck('id')->all());
            $got = $received->get($shop->id, collect())->pluck('c', 'agent_id');

            return [
                'id' => $shop->id,
                'name' => $shop->name,
                'is_open' => (bool) $shop->is_open,
                'received_today' => (int) $got->sum(),
                'sellers' => $agents->map(function (User $u) use ($targets, $active, $got) {
                    $m = $u->salesGroupMembership;
                    $group = $m && $m->group?->is_active ? $m->group : null;
                    $count = $active[$u->id] ?? 0;

                    return [
                        'id' => $u->id,
                        'name' => trim($u->names . ' ' . $u->surnames),
                        'group' => $group ? ['id' => $group->id, 'name' => $group->name] : null,
                        'is_leader' => $group && $m->role === SalesGroupMember::ROLE_LEADER,
                        'weight' => $group ? $m->weight : null,
                        'active_orders' => $count,
                        'max_active_orders' => $u->max_active_orders,
                        'at_capacity' => $u->max_active_orders !== null && $count >= $u->max_active_orders,
                        'target_share' => $targets[$u->id] ?? 0,
                        'received_today' => (int) ($got[$u->id] ?? 0),
                    ];
                })->sortByDesc('target_share')->values(),
            ];
        });

        return response()->json([
            'status' => true,
            'data' => [
                'shops' => $shops,
                'load_balanced' => Setting::get('assignment_strategy', 'round_robin') === 'load_balanced',
            ],
        ]);
    }

    /** POST /assignment/reset { shop_id? } — el reparto de la tienda (o de todas) vuelve a empezar desde cero. */
    public function reset(Request $request, WeightedAssigner $assigner)
    {
        $shopId = $request->integer('shop_id') ?: null;
        $assigner->reset($shopId ? WeightedAssigner::poolFor($shopId) : null);

        return response()->json(['status' => true, 'message' => 'Turnos reiniciados']);
    }

    /** PUT /assignment/sellers/{user} { max_active_orders } — tope de órdenes activas (null = sin tope). */
    public function updateSeller(Request $request, User $user)
    {
        $data = $request->validate(['max_active_orders' => 'nullable|integer|min:1|max:999']);
        if ($user->role?->description !== 'Vendedor') {
            throw ValidationException::withMessages(['user' => 'El usuario no es vendedora.']);
        }
        $user->update(['max_active_orders' => $data['max_active_orders'] ?? null]);

        return response()->json(['status' => true, 'message' => 'Máximo actualizado']);
    }

    /**
     * GET /assignment/reassign/preview?from_agent_id= — cuántas órdenes tiene por estado y quiénes son
     * las demás de su grupo, para armar la reasignación en bloque.
     */
    public function reassignPreview(Request $request)
    {
        $data = $request->validate(['from_agent_id' => 'required|integer|exists:users,id']);
        $fromId = (int) $data['from_agent_id'];

        $statuses = Status::whereIn('description', BulkReassignService::REASSIGNABLE_STATUSES)->get(['id', 'description']);
        $counts = Order::where('agent_id', $fromId)
            ->whereIn('status_id', $statuses->pluck('id'))
            ->groupBy('status_id')
            ->selectRaw('status_id, COUNT(*) as c')
            ->pluck('c', 'status_id');

        $membership = SalesGroupMember::open()->where('user_id', $fromId)->whereHas('group', fn ($q) => $q->where('is_active', true))->first();
        $groupMates = $membership
            ? SalesGroupMember::open()
                ->where('sales_group_id', $membership->sales_group_id)
                ->where('role', SalesGroupMember::ROLE_SELLER)
                ->where('user_id', '!=', $fromId)
                ->pluck('user_id')
            : collect();

        return response()->json([
            'status' => true,
            'data' => [
                'statuses' => $statuses->map(fn ($s) => [
                    'id' => $s->id,
                    'description' => $s->description,
                    'count' => (int) ($counts[$s->id] ?? 0),
                    'default' => in_array($s->description, WeightedAssigner::ACTIVE_STATUSES, true),
                ])->values(),
                'group_mate_ids' => $groupMates->values(),
            ],
        ]);
    }

    /** POST /assignment/reassign { from_agent_id, to_agent_ids[], status_ids[] } */
    public function reassign(Request $request, BulkReassignService $service)
    {
        $allowed = Status::whereIn('description', BulkReassignService::REASSIGNABLE_STATUSES)->pluck('id')->all();
        $data = $request->validate([
            'from_agent_id' => 'required|integer|exists:users,id',
            'to_agent_ids' => 'required|array|min:1',
            'to_agent_ids.*' => 'integer|distinct|exists:users,id',
            'status_ids' => 'required|array|min:1',
            'status_ids.*' => ['integer', Rule::in($allowed)],
        ]);

        $sellerRoleId = Role::where('description', 'Vendedor')->value('id');
        $targets = array_values(array_diff(array_map('intval', $data['to_agent_ids']), [(int) $data['from_agent_id']]));
        if ($targets === [] || User::whereIn('id', $targets)->where('role_id', $sellerRoleId)->count() !== count($targets)) {
            throw ValidationException::withMessages(['to_agent_ids' => 'Elige al menos una vendedora de destino distinta de la de origen.']);
        }

        $result = $service->reassign((int) $data['from_agent_id'], $targets, $data['status_ids'], Auth::id());
        $total = array_sum($result);

        return response()->json([
            'status' => true,
            'message' => $total === 1 ? 'Se reasignó 1 orden' : "Se reasignaron {$total} órdenes",
            'data' => ['moved' => $result, 'total' => $total],
        ]);
    }
}
