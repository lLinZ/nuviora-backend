<?php

namespace App\Http\Controllers;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsCenter;
use App\Models\LogisticsOrder;
use App\Models\RiderProfile;
use App\Models\User;
use App\Services\Logistics\Candidates;
use App\Services\Logistics\LogisticsSettings;
use App\Services\Logistics\PushSender;
use App\Models\DelivererCostSetting;
use App\Services\Logistics\RiderCash;
use App\Services\Logistics\RiderPay;
use App\Services\Logistics\RiderEta;
use App\Services\Logistics\Shifts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Los riders de los centros (logística propia, §6, §21.2, §23). El Admin los da de alta en un centro, los suspende y les
 * pone franjas; el operador ve los de su centro con su ubicación, su ETA al centro y si la app está lista para la alarma.
 * Los usuarios se crean como siempre, con el rol Repartidor (Usuarios › Repartidores).
 */
class LogisticsRiderController extends Controller
{
    public function __construct(private Shifts $shifts, private RiderEta $eta, private LogisticsSettings $settings, private RiderCash $cash)
    {
    }

    /** Admin: todos los usuarios Repartidor, con su perfil de rider si lo tienen. */
    public function index(Request $request)
    {
        $users = User::whereHas('role', fn ($q) => $q->where('description', 'Repartidor'))->orderBy('names')
            ->get(['id', 'names', 'surnames', 'phone', 'email']);
        $profiles = RiderProfile::with('center:id,name')->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        return response()->json([
            'status' => true,
            'riders' => $users->map(fn (User $u) => $u->toArray() + ['profile' => isset($profiles[$u->id]) ? $this->present($profiles[$u->id]) : null]),
        ]);
    }

    /** Admin: alta o cambio del perfil (§6.1: "activo/habilitado administrativamente… no estar suspendido"). */
    public function update(Request $request, User $user)
    {
        if ($user->role?->description !== 'Repartidor') {
            return response()->json(['status' => false, 'message' => 'Solo un usuario Repartidor puede ser rider de un centro.'], 422);
        }
        $data = $request->validate([
            'center_id' => 'required|integer|exists:logistics_centers,id',
            'is_active' => 'required|boolean',
            'suspended' => 'required|boolean',
            'suspended_reason' => 'nullable|required_if:suspended,true|string|max:255',
            'schedule' => 'nullable|array',
            'schedule.*' => 'nullable|array|size:2',
            'schedule.*.*' => 'date_format:H:i',
            'notes' => 'nullable|string|max:2000',
        ]);
        $profile = RiderProfile::firstOrNew(['user_id' => $user->id]);
        $wasSuspended = $profile->suspended_at !== null;
        $profile->fill([
            'center_id' => $data['center_id'],
            'is_active' => $data['is_active'],
            'schedule' => $data['schedule'] ?? null,
            'notes' => $data['notes'] ?? null,
            'suspended_at' => $data['suspended'] ? ($wasSuspended ? $profile->suspended_at : now()) : null,
            'suspended_reason' => $data['suspended'] ? $data['suspended_reason'] : null,
        ])->save();
        // §18: el rider de un centro se paga por tarifario, desde el almacén del centro (R11, OwnDelivererCost)
        $warehouseId = LogisticsCenter::whereKey($data['center_id'])->value('warehouse_id');
        $cost = DelivererCostSetting::firstOrNew(['user_id' => $user->id]);
        if ($cost->mode !== DelivererCostSetting::TARIFF || (int) $cost->warehouse_id !== (int) $warehouseId) {
            $cost->fill(['mode' => DelivererCostSetting::TARIFF, 'warehouse_id' => $warehouseId, 'updated_by' => Auth::id()])->save();
        }

        return response()->json(['status' => true, 'message' => 'Rider guardado.', 'profile' => $this->present($profile->fresh('center'))]);
    }

    /** Admin: una alarma de prueba al teléfono del rider, para ver que suena (sin orden detrás). */
    public function testPush(User $user, PushSender $push)
    {
        $profile = RiderProfile::where('user_id', $user->id)->first();
        if (!$profile) {
            return response()->json(['status' => false, 'message' => 'Ese usuario no es rider de un centro.'], 422);
        }
        $result = $push->send($profile, ['type' => 'prueba', 'center' => (string) $profile->center?->name, 'sent_at' => now()->toIso8601String()], 60);

        return response()->json(['status' => $result['ok'], 'message' => $result['ok'] ? 'Alarma de prueba enviada.' : $result['error']], $result['ok'] ? 200 : 422);
    }

    /** Operador: los riders de su centro, con su ubicación y su ETA al centro (§21.2). Se le pregunta a Google solo si el ETA tiene más de un minuto. */
    public function forCenter(LogisticsCenter $center)
    {
        $user = Auth::user();
        if (!Shifts::isAdmin($user) && !$this->shifts->isOperatorOf($user, $center)) {
            return response()->json(['status' => false, 'message' => 'No eres operador de este centro.'], 403);
        }
        $profiles = RiderProfile::with('user:id,names,phone')->where('center_id', $center->id)->orderBy('id')->get();
        $warning = $this->eta->refreshToCenter($profiles->filter(fn ($p) => $p->is_active && !$p->suspended_at), $center);
        $busy = LogisticsOrder::with('order:id,name')->whereIn('status', Candidates::BUSY)->whereIn('rider_id', $profiles->pluck('user_id'))
            ->get()->groupBy('rider_id');
        $gpsMaxAge = (int) $this->settings->for($center)['gps_max_age_seconds'];
        // §21.2 "rutas del día": las que salieron hoy con ese rider (hoy cada ruta es de una parada)
        $routesToday = LogisticsOrder::whereIn('rider_id', $profiles->pluck('user_id'))->whereDate('departed_at', now()->toDateString())
            ->selectRaw('rider_id, count(*) as total')->groupBy('rider_id')->pluck('total', 'rider_id');
        // §21.2: "efectivo bajo custodia, retornos bajo custodia"
        $cash = $this->cash->balances($profiles->pluck('user_id'));
        $cashLimit = $this->cash->limit($center);
        $returns = LogisticsOrder::where('status', LogisticsStatus::RETURN_PENDING)->whereIn('rider_id', $profiles->pluck('user_id'))
            ->selectRaw('rider_id, count(*) as total')->groupBy('rider_id')->pluck('total', 'rider_id');

        return response()->json([
            'status' => true,
            'warning' => $warning,
            'cash_limit_usd' => $cashLimit,
            'riders' => $profiles->map(fn (RiderProfile $p) => $this->present($p->fresh(['user', 'center'])) + [
                'user' => $p->user?->only(['id', 'names', 'phone']),
                'gps_ok' => $p->hasLocation() && $p->last_location_at->gte(now()->subSeconds($gpsMaxAge)),
                'routes_today' => (int) ($routesToday[$p->user_id] ?? 0),
                'cash' => $cash[$p->user_id],
                'cash_over_limit' => $cashLimit > 0 && $cash[$p->user_id]['total_usd'] >= $cashLimit,
                'returns_pending' => (int) ($returns[$p->user_id] ?? 0),
                'current' => ($busy[$p->user_id] ?? collect())->map(fn (LogisticsOrder $r) => [
                    'logistics_order_id' => $r->id, 'order' => $r->order?->name, 'status' => $r->status, 'status_label' => LogisticsStatus::label($r->status),
                ])->values(),
            ]),
        ]);
    }

    /**
     * Operador: el rider le entrega el efectivo que cobró (§17 puntos 4 y 5). Lo esperado es su saldo; si lo recibido no
     * cuadra, queda una incidencia de caja y la diferencia sigue en su saldo. POST { usd, ves, note? }
     */
    public function receiveCash(Request $request, LogisticsCenter $center, User $user)
    {
        $by = Auth::user();
        if (!Shifts::isAdmin($by) && !$this->shifts->isOperatorOf($by, $center)) {
            return response()->json(['status' => false, 'message' => 'No eres operador de este centro.'], 403);
        }
        if (!RiderProfile::where('user_id', $user->id)->where('center_id', $center->id)->exists()) {
            return response()->json(['status' => false, 'message' => 'Ese rider no es de este centro.'], 422);
        }
        $data = $request->validate(['usd' => 'nullable|numeric|min:0|max:100000', 'ves' => 'nullable|numeric|min:0|max:1000000000', 'note' => 'nullable|string|max:250']);
        $out = $this->cash->receive($center, $user->id, $by, (float) ($data['usd'] ?? 0), (float) ($data['ves'] ?? 0), $data['note'] ?? null);

        return response()->json(['status' => $out['ok'], 'message' => $out['message'], 'balance' => $out['balance'] ?? null], $out['code']);
    }

    /** §18.5: las carreras del día de cada rider del centro, lo pagado, lo pendiente y el fondo que hace falta. ?date=AAAA-MM-DD */
    public function payouts(Request $request, LogisticsCenter $center, RiderPay $pay)
    {
        $by = Auth::user();
        if (!Shifts::isAdmin($by) && !$this->shifts->isOperatorOf($by, $center)) {
            return response()->json(['status' => false, 'message' => 'No eres operador de este centro.'], 403);
        }
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d|before_or_equal:today']);

        return response()->json(['status' => true] + $pay->day($center, $data['date'] ?? now()->toDateString()));
    }

    /** §18.5: el operador le paga al rider exactamente lo indicado. POST { date, amount, method, note? } */
    public function payRider(Request $request, LogisticsCenter $center, User $user, RiderPay $pay)
    {
        $by = Auth::user();
        if (!Shifts::isAdmin($by) && !$this->shifts->isOperatorOf($by, $center)) {
            return response()->json(['status' => false, 'message' => 'No eres operador de este centro.'], 403);
        }
        $data = $request->validate(['date' => 'required|date_format:Y-m-d|before_or_equal:today', 'amount' => 'required|numeric|min:0.01|max:10000',
            'method' => 'required|string|max:40', 'note' => 'nullable|string|max:250']);
        $out = $pay->pay($center, $user->id, $data['date'], $by, (float) $data['amount'], $data['method'], $data['note'] ?? null);

        return response()->json(['status' => $out['ok'], 'message' => $out['message']], $out['code']);
    }

    /** Operador: a quién le llegaría la ronda de esta orden ahora, y por qué no a los demás (§7: explicable). No guarda nada. */
    public function candidates(LogisticsOrder $logisticsOrder, Candidates $candidates)
    {
        $user = Auth::user();
        $center = $logisticsOrder->center;
        if (!Shifts::isAdmin($user) && !$this->shifts->isOperatorOf($user, $center)) {
            return response()->json(['status' => false, 'message' => 'No eres operador de este centro.'], 403);
        }

        return response()->json(['status' => true] + $candidates->for($logisticsOrder));
    }

    private function present(RiderProfile $p): array
    {
        return $p->only([
            'id', 'user_id', 'center_id', 'is_active', 'suspended_at', 'suspended_reason', 'schedule', 'platform', 'app_version',
            'notifications_enabled', 'location_permission', 'device_reported_at', 'push_error', 'last_lat', 'last_lng',
            'last_accuracy', 'last_location_at', 'eta_center_seconds', 'eta_center_meters', 'eta_computed_at', 'notes',
        ]) + [
            'center' => $p->center?->only(['id', 'name']),
            'has_push' => $p->hasPushTarget(),
            'alarm_ready' => $p->hasPushTarget() && $p->notifications_enabled !== false && $p->location_permission === RiderProfile::PERMISSION_ALWAYS,
        ];
    }
}
