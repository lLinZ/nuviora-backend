<?php

// app/Http/Controllers/SettingsController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\DailyAgentRoster;

class SettingsController extends Controller
{
    protected function ensureManager(): void
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Gerente', 'Admin'])) abort(403, 'No autorizado');
    }

    public function getBusinessHours()
    {
        $this->ensureManager();
        return response()->json([
            'status' => true,
            'data'   => [
                'open'  => Setting::get('business_open_at', '09:00'),
                'close' => Setting::get('business_close_at', '18:00'),
            ],
        ]);
    }

    public function updateBusinessHours(Request $request)
    {
        $this->ensureManager();

        $request->validate([
            'open'  => ['required', 'regex:/^\d{2}:\d{2}$/'],  // HH:mm
            'close' => ['required', 'regex:/^\d{2}:\d{2}$/'],  // HH:mm
        ]);

        Setting::set('business_open_at',  $request->open);
        Setting::set('business_close_at', $request->close);

        return response()->json([
            'status'  => true,
            'message' => 'Horario actualizado',
            'data'    => ['open' => $request->open, 'close' => $request->close],
        ]);
    }

    /**
     * GET /settings/round-robin
     * Devuelve el estado completo de los round-robins de todas las tiendas:
     * - Estrategia global (round_robin | load_balanced)
     * - Por cada tienda:
     *   · Roster Normal (daily_agent_rosters del día de hoy)
     *   · Roster Sin Stock (subconjunto con can_handle_no_stock = true, mismo pointer)
     *   · Roster Por Defecto (shop_user con is_default_roster = true)
     *   · Quién fue el último en recibir una orden y quién sigue
     */
    public function getRoundRobin()
    {
        $this->ensureManager();

        $strategy = Setting::get('assignment_strategy', 'round_robin');
        $today    = now()->toDateString();

        // Cargamos todas las tiendas con sus vendedoras permanentes (pivot)
        $shops = Shop::with(['sellers' => function ($q) {
            $q->select('users.id', 'users.names', 'users.surnames', 'users.can_handle_no_stock')
              ->withPivot('is_default_roster');
        }])->get();

        // Cargamos todos los rosters activos de hoy en una sola query
        $todayRosters = DailyAgentRoster::with(['agent' => function ($q) {
            $q->select('id', 'names', 'surnames', 'can_handle_no_stock');
        }])
            ->where('date', $today)
            ->where('is_active', true)
            ->get()
            ->groupBy('shop_id');

        $result = $shops->map(function ($shop) use ($todayRosters, $today) {
            $pointerKey  = 'round_robin_pointer_' . $shop->id;
            $lastAgentId = Setting::get($pointerKey, null);

            // ── Roster Normal (activos hoy) ──────────────────────────────────
            $activeRosters = $todayRosters->get($shop->id, collect());
            $normalAgents  = $activeRosters
                ->map(fn($r) => $r->agent)
                ->filter()
                ->sortBy('id')
                ->values();

            // ── Roster Sin Stock (subconjunto del normal) ────────────────────
            $noStockAgents = $normalAgents->filter(fn($a) => $a->can_handle_no_stock)->values();

            // ── Roster Por Defecto (pivot shop_user) ─────────────────────────
            $defaultAgents = $shop->sellers
                ->filter(fn($s) => $s->pivot->is_default_roster == 1 || $s->pivot->is_default_roster === true)
                ->sortBy('id')
                ->values();

            // ── Helper: dado una colección de agentes y el pointer, calcula last/next ──
            $resolveQueue = function ($agents) use ($lastAgentId) {
                if ($agents->isEmpty()) return [];

                $pos = $agents->search(fn($a) => $a->id == $lastAgentId);

                // Si no hay pointer aún o el agente ya no está en el roster → empieza en 0
                $lastIdx = ($pos === false) ? null : $pos;
                $nextIdx = ($pos === false) ? 0   : ($pos + 1) % $agents->count();

                return $agents->map(function ($agent, $idx) use ($lastIdx, $nextIdx) {
                    return [
                        'id'                 => $agent->id,
                        'name'               => trim($agent->names . ' ' . ($agent->surnames ?? '')),
                        'can_handle_no_stock'=> (bool) $agent->can_handle_no_stock,
                        'is_last'            => $lastIdx !== null && $idx === $lastIdx,
                        'is_next'            => $idx === $nextIdx,
                    ];
                })->values()->all();
            };

            return [
                'id'             => $shop->id,
                'name'           => $shop->name,
                'is_open'        => $shop->is_open,
                'pointer_agent_id' => $lastAgentId ? (int) $lastAgentId : null,
                'roster_normal'  => $resolveQueue($normalAgents),
                'roster_no_stock'=> $resolveQueue($noStockAgents),
                'roster_default' => $defaultAgents->map(fn($a) => [
                    'id'                  => $a->id,
                    'name'                => trim($a->names . ' ' . ($a->surnames ?? '')),
                    'can_handle_no_stock' => (bool) $a->can_handle_no_stock,
                ])->values()->all(),
            ];
        })->values();

        return response()->json([
            'status' => true,
            'data'   => [
                'strategy' => $strategy,
                'shops'    => $result,
            ],
        ]);
    }

    /**
     * POST /settings/round-robin/reset
     * Body: { shop_id: int|null }  — null = resetea todas las tiendas
     */
    public function resetRoundRobin(Request $request)
    {
        $this->ensureManager();

        $shopId = $request->input('shop_id');

        if ($shopId) {
            Setting::where('key', 'round_robin_pointer_' . $shopId)->delete();
        } else {
            // Resetear todos
            Setting::where('key', 'like', 'round_robin_pointer_%')->delete();
        }

        return response()->json([
            'status'  => true,
            'message' => $shopId
                ? "Pointer de la tienda #{$shopId} reseteado"
                : 'Todos los pointers han sido reseteados',
        ]);
    }

    /**
     * POST /settings/round-robin/pointer
     * Body: { shop_id: int, agent_id: int }
     * Mueve el pointer para que el SIGUIENTE en recibir sea agent_id
     * (seteamos el pointer al agente ANTERIOR en la lista, o al que esté justo antes)
     */
    public function setRoundRobinPointer(Request $request)
    {
        $this->ensureManager();

        $request->validate([
            'shop_id'  => 'required|integer|exists:shops,id',
            'agent_id' => 'required|integer|exists:users,id',
        ]);

        $today  = now()->toDateString();
        $shopId = $request->shop_id;

        // Obtenemos el roster del día para calcular el índice anterior
        $agents = DailyAgentRoster::with('agent:id,names')
            ->where('date', $today)
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->get()
            ->map(fn($r) => $r->agent)
            ->filter()
            ->sortBy('id')
            ->values();

        $pos = $agents->search(fn($a) => $a->id == $request->agent_id);

        if ($pos === false) {
            return response()->json(['status' => false, 'message' => 'El agente no está en el roster activo de hoy'], 422);
        }

        // El pointer guarda quién fue el ÚLTIMO, así que para que "siga" agent_id
        // debemos poner el pointer en el agente anterior
        $prevIdx  = ($pos - 1 + $agents->count()) % $agents->count();
        $prevAgent = $agents[$prevIdx];

        Setting::set('round_robin_pointer_' . $shopId, (string) $prevAgent->id);

        return response()->json([
            'status'  => true,
            'message' => "El siguiente en recibir orden será: {$agents[$pos]->names}",
        ]);
    }

    /**
     * PUT /settings/strategy
     * Body: { strategy: "round_robin" | "load_balanced" }
     */
    public function updateStrategy(Request $request)
    {
        $this->ensureManager();

        $request->validate([
            'strategy' => 'required|in:round_robin,load_balanced',
        ]);

        Setting::set('assignment_strategy', $request->strategy);

        return response()->json([
            'status'  => true,
            'message' => 'Estrategia actualizada a: ' . $request->strategy,
            'data'    => ['strategy' => $request->strategy],
        ]);
    }
}
