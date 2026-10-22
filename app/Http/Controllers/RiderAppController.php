<?php

namespace App\Http\Controllers;

use App\Constants\LogisticsStatus;
use App\Models\DispatchOffer;
use App\Models\LogisticsOrder;
use App\Models\RiderLocation;
use App\Models\RiderProfile;
use App\Services\Delivery\TariffBook;
use App\Services\Logistics\Candidates;
use App\Services\Logistics\Dispatcher;
use App\Services\Logistics\LogisticsSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Lo que usa la app del rider (logística propia, §6, §22; R1: Android por ahora). El rider solo ve lo suyo: su perfil,
 * su centro y, cuando gana una ronda, su ruta (§3: no ve otros pedidos ni stock).
 */
class RiderAppController extends Controller
{
    /** Cada cuánto manda la app su ubicación (decisión nuestra; ver tasks/logistica-propia/plan.md, 1.1). */
    public const LOCATION_EVERY_SECONDS = 30;
    public const LOCATION_EVERY_METERS = 100;

    public function me()
    {
        $profile = $this->profile();
        $center = $profile->center()->with('warehouse:id,name,latitude,longitude,maps_url')->first();

        return response()->json([
            'status' => true,
            'rider' => Auth::user()->only(['id', 'names', 'surnames', 'phone']),
            'profile' => $profile->only(['is_active', 'suspended_at', 'schedule', 'notifications_enabled', 'location_permission', 'last_location_at', 'push_error']),
            'center' => $center ? [
                'id' => $center->id,
                'name' => $center->name,
                'address' => $center->address,
                'latitude' => $center->warehouse?->latitude,
                'longitude' => $center->warehouse?->longitude,
                'maps_url' => $center->warehouse?->maps_url,
                'opening_hours' => $center->opening_hours,
            ] : null,
            'location' => ['every_seconds' => self::LOCATION_EVERY_SECONDS, 'every_meters' => self::LOCATION_EVERY_METERS],
            'gps_max_age_seconds' => app(LogisticsSettings::class)->for($center)['gps_max_age_seconds'],
        ]);
    }

    /** El teléfono del rider: a dónde mandarle la alarma y si tiene los permisos que hacen falta (§6.1, R1). */
    public function device(Request $request)
    {
        $data = $request->validate([
            'push_target_type' => 'nullable|in:fid,token',
            'push_target' => 'nullable|required_with:push_target_type|string|max:4096',
            'platform' => 'required|in:android,ios',
            'app_version' => 'nullable|string|max:30',
            'notifications_enabled' => 'required|boolean',
            'location_permission' => 'required|in:siempre,en_uso,negado',
        ]);
        $profile = $this->profile();
        $changedTarget = ($data['push_target'] ?? null) !== null && $data['push_target'] !== $profile->push_target;
        $profile->fill([
            'platform' => $data['platform'],
            'app_version' => $data['app_version'] ?? null,
            'notifications_enabled' => $data['notifications_enabled'],
            'location_permission' => $data['location_permission'],
            'device_reported_at' => now(),
        ]);
        if (array_key_exists('push_target', $data) && $data['push_target'] !== null) {
            $profile->fill(['push_target_type' => $data['push_target_type'], 'push_target' => $data['push_target']]);
            if ($changedTarget) {
                $profile->push_error = null;
            }
        }
        $profile->save();

        return response()->json(['status' => true, 'message' => 'Teléfono registrado.', 'ready' => $profile->hasPushTarget() && $profile->notifications_enabled && $profile->location_permission === RiderProfile::PERMISSION_ALWAYS]);
    }

    /** Ubicaciones de la app, de a varias (la app las junta si se queda sin señal). Se queda la más nueva como última. */
    public function locations(Request $request)
    {
        $data = $request->validate([
            'points' => 'required|array|min:1|max:200',
            'points.*.lat' => 'required|numeric|between:-90,90',
            'points.*.lng' => 'required|numeric|between:-180,180',
            'points.*.accuracy' => 'nullable|numeric|min:0',
            'points.*.speed' => 'nullable|numeric',
            'points.*.recorded_at' => 'required|date',
        ]);
        $profile = $this->profile();
        $now = now();
        $rows = [];
        $latest = null;
        foreach ($data['points'] as $point) {
            $at = Carbon::parse($point['recorded_at']);
            // Un reloj del teléfono adelantado no puede dejar una ubicación "del futuro" como la más reciente
            if ($at->gt($now->copy()->addMinutes(2))) {
                $at = $now->copy();
            }
            $rows[] = [
                'user_id' => $profile->user_id,
                'lat' => $point['lat'],
                'lng' => $point['lng'],
                'accuracy' => $point['accuracy'] ?? null,
                'speed' => $point['speed'] ?? null,
                'recorded_at' => $at,
                'created_at' => $now,
            ];
            if (!$latest || $at->gt($latest['recorded_at'])) {
                $latest = end($rows);
            }
        }
        DB::transaction(function () use ($rows, $latest, $profile) {
            RiderLocation::insert($rows);
            if (!$profile->last_location_at || $latest['recorded_at']->gt($profile->last_location_at)) {
                $profile->forceFill([
                    'last_lat' => $latest['lat'],
                    'last_lng' => $latest['lng'],
                    'last_accuracy' => $latest['accuracy'],
                    'last_location_at' => $latest['recorded_at'],
                ])->save();
            }
        });

        return response()->json(['status' => true, 'saved' => count($rows)]);
    }

    /**
     * Las oportunidades abiertas del rider (§8, §22.1). Sin nada del destino: ni zona, ni cliente, ni km, ni pago, ni
     * monto, ni producto (§8.1). Solo el centro, al que tiene que poder ir de inmediato.
     */
    public function offers()
    {
        $profile = $this->profile();
        $center = $profile->center;
        $offers = DispatchOffer::with('round:id,number')->where('rider_id', $profile->user_id)->whereNull('response')
            ->where('expires_at', '>', now())->orderBy('id')->get();

        return response()->json([
            'status' => true,
            'offers' => $offers->map(fn (DispatchOffer $o) => [
                'id' => $o->id,
                'round' => $o->round?->number,
                'expires_at' => $o->expires_at->toIso8601String(),
                'seconds_left' => max(0, (int) now()->diffInSeconds($o->expires_at, false)),
                'message' => 'Nueva asignación disponible. Debes poder dirigirte al centro logístico inmediatamente. Confirmar disponibilidad.',
                'center' => ['name' => $center?->name, 'address' => $center?->address],
            ]),
        ]);
    }

    /** §26: "seen_at si existe". */
    public function seen(DispatchOffer $offer)
    {
        $profile = $this->profile();
        abort_unless((int) $offer->rider_id === (int) $profile->user_id, 403);
        if (!$offer->seen_at) {
            $offer->forceFill(['seen_at' => now()])->save();
        }

        return response()->json(['status' => true]);
    }

    /** §8.2: el primero que confirma gana; a los demás, "Ya otro rider aceptó". */
    public function confirm(DispatchOffer $offer, Dispatcher $dispatcher)
    {
        $this->profile();
        $out = $dispatcher->confirm($offer, Auth::user());

        return response()->json(['status' => $out['ok'], 'message' => $out['message']], $out['code']);
    }

    /**
     * Lo que el rider ve después de confirmar (§9.1): el centro para recoger, el cliente, la dirección y su GPS, el cobro y
     * cómo pagó, y su pago estimado si ya tiene tarifario. Solo sus órdenes en curso (§3).
     */
    public function assignment(TariffBook $tariffs)
    {
        $profile = $this->profile();
        $rows = LogisticsOrder::with(['center.warehouse', 'order.client', 'order.payments'])
            ->where('rider_id', $profile->user_id)->whereIn('status', Candidates::BUSY)->orderBy('id')->get();

        return response()->json([
            'status' => true,
            'assignments' => $rows->map(function (LogisticsOrder $row) use ($tariffs, $profile) {
                $order = $row->order;
                $client = $order?->client;
                $tariff = $row->center ? $tariffs->find((int) $profile->user_id, (int) $row->center->city_id, now(config('app.timezone'))->toDateString()) : null;
                $quote = $tariff && $row->center_client_meters !== null ? $tariffs->quote($tariff, (int) $row->center_client_meters) : null;

                return [
                    'logistics_order_id' => $row->id,
                    'status' => $row->status,
                    'status_label' => LogisticsStatus::label($row->status),
                    'next_action' => self::NEXT_ACTION[$row->status] ?? null,
                    'center' => [
                        'name' => $row->center?->name,
                        'address' => $row->center?->address,
                        'latitude' => $row->center?->warehouse?->latitude,
                        'longitude' => $row->center?->warehouse?->longitude,
                        'maps_url' => $row->center?->warehouse?->maps_url,
                    ],
                    'order' => [
                        'id' => $order?->id,
                        'name' => $order?->name,
                        'total' => (float) $order?->current_total_price,
                        'currency' => $order?->currency,
                        'payment_method' => $order?->payment_method,
                        'payments' => $order?->payments->map(fn ($p) => ['method' => $p->method, 'amount' => (float) $p->amount])->values(),
                        'courier_shipment' => (bool) $order?->courier_shipment,
                    ],
                    'client' => [
                        'name' => trim(($client?->first_name ?? '') . ' ' . ($client?->last_name ?? '')),
                        'phone' => $client?->phone,
                        'address' => $client?->address1,
                        'location' => $order?->location,
                        'latitude' => $order?->location_lat,
                        'longitude' => $order?->location_lng,
                    ],
                    'estimated_pay' => $quote ? (float) $quote['price'] : null,
                ];
            }),
        ]);
    }

    public const NEXT_ACTION = [
        LogisticsStatus::RIDER_CONFIRMED => 'Ve al centro a recoger el paquete.',
        LogisticsStatus::PREPARING => 'Ve al centro: el operador está preparando el paquete.',
        LogisticsStatus::READY => 'El paquete está listo: recógelo en el centro.',
        LogisticsStatus::EN_ROUTE => 'Lleva el paquete al cliente y marca "Entregado" con la foto del comprobante.',
        LogisticsStatus::INCIDENT => 'Novedad abierta: espera las instrucciones del operador.',
    ];

    private function profile(): RiderProfile
    {
        $profile = RiderProfile::where('user_id', Auth::id())->first();
        if (!$profile) {
            abort(response()->json(['status' => false, 'message' => 'Todavía no estás dado de alta como rider de un centro. Habla con administración.'], 403));
        }

        return $profile;
    }
}
