<?php

namespace App\Http\Controllers;

use App\Models\RiderLocation;
use App\Models\RiderProfile;
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

    private function profile(): RiderProfile
    {
        $profile = RiderProfile::where('user_id', Auth::id())->first();
        if (!$profile) {
            abort(response()->json(['status' => false, 'message' => 'Todavía no estás dado de alta como rider de un centro. Habla con administración.'], 403));
        }

        return $profile;
    }
}
