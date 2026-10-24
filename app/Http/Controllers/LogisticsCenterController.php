<?php

namespace App\Http\Controllers;

use App\Models\LogisticsCenter;
use App\Models\LogisticsCenterException;
use App\Models\LogisticsSetting;
use App\Models\OperatorOverride;
use App\Models\OperatorPattern;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Logistics\Centers;
use App\Services\Logistics\LogisticsSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Configuración de los centros logísticos (logística propia, §23): centro, horario, excepciones, cutoff, operadores y
 * parámetros con versión (§31, §33). Solo Admin, como el resto de la configuración. Nada se hardcodea (§30).
 */
class LogisticsCenterController extends Controller
{
    public function __construct(private Centers $centers, private LogisticsSettings $settings)
    {
    }

    public function index()
    {
        $centers = LogisticsCenter::with(['city:id,name', 'warehouse:id,name,maps_url,latitude,longitude', 'operators:id,names,email', 'activeShift.user:id,names'])
            ->orderBy('id')->get()
            ->map(fn (LogisticsCenter $c) => $this->present($c));

        return response()->json([
            'status' => true,
            'centers' => $centers,
            'operator_candidates' => User::whereHas('role', fn ($q) => $q->where('description', 'Operador logístico'))
                ->orderBy('names')->get(['id', 'names', 'email']),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('id')->get(['id', 'name', 'city_id', 'user_id', 'latitude', 'longitude']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $center = LogisticsCenter::create($data + ['opening_hours' => Centers::DEFAULT_HOURS, 'updated_by' => Auth::id()]);

        return response()->json(['status' => true, 'message' => 'Centro creado (apagado).', 'center' => $this->present($center->fresh())]);
    }

    public function update(Request $request, LogisticsCenter $center)
    {
        $data = $this->validated($request, $center);
        $center->update($data + ['updated_by' => Auth::id()]);

        return response()->json(['status' => true, 'message' => 'Centro actualizado.', 'center' => $this->present($center->fresh())]);
    }

    /** Encender o apagar el centro de la ciudad (audio del 21/10: "cuando todo funcione, habilitamos Caracas y Maracay"). */
    public function setActive(Request $request, LogisticsCenter $center)
    {
        $request->validate(['is_active' => 'required|boolean']);
        if ($request->boolean('is_active') && ($problems = $this->centers->activationProblems($center))) {
            return response()->json(['status' => false, 'message' => implode(' ', $problems), 'problems' => $problems], 422);
        }
        $center->update(['is_active' => $request->boolean('is_active'), 'updated_by' => Auth::id()]);

        return response()->json([
            'status' => true,
            'message' => $center->is_active
                ? "Centro encendido: las órdenes de {$center->city?->name} que pasen a \"Asignar a agencia\" van al centro."
                : "Centro apagado: {$center->city?->name} vuelve a sus agencias. Lo que ya estaba en el centro sigue ahí.",
            'center' => $this->present($center->fresh()),
        ]);
    }

    public function syncOperators(Request $request, LogisticsCenter $center)
    {
        $data = $request->validate([
            'operators' => 'present|array|max:10',
            'operators.*.user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'operators.*.coverage' => 'nullable|in:principal,suplente',
        ]);
        $ids = collect($data['operators'])->pluck('user_id')->all();
        $valid = User::whereIn('id', $ids)->whereHas('role', fn ($q) => $q->where('description', 'Operador logístico'))->pluck('id')->all();
        if (count($valid) !== count(array_unique($ids))) {
            return response()->json(['status' => false, 'message' => 'Solo usuarios con el rol "Operador logístico" pueden ser operadores del centro.'], 422);
        }
        $center->operators()->sync(collect($data['operators'])->mapWithKeys(fn ($o) => [$o['user_id'] => ['coverage' => $o['coverage'] ?? 'principal']])->all());

        return response()->json(['status' => true, 'message' => 'Operadores guardados.', 'center' => $this->present($center->fresh())]);
    }

    public function storeException(Request $request, LogisticsCenter $center)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'is_closed' => 'required|boolean',
            'opens_at' => 'nullable|required_if:is_closed,false|date_format:H:i',
            'closes_at' => 'nullable|required_if:is_closed,false|date_format:H:i|after:opens_at',
            'note' => 'nullable|string|max:255',
        ]);
        $exception = LogisticsCenterException::updateOrCreate(
            ['center_id' => $center->id, 'date' => $data['date']],
            [
                'is_closed' => $data['is_closed'],
                'opens_at' => $data['is_closed'] ? null : $data['opens_at'],
                'closes_at' => $data['is_closed'] ? null : $data['closes_at'],
                'note' => $data['note'] ?? null,
                'created_by' => Auth::id(),
            ]
        );

        return response()->json(['status' => true, 'message' => 'Excepción guardada.', 'exception' => $exception]);
    }

    public function destroyException(LogisticsCenter $center, LogisticsCenterException $exception)
    {
        abort_unless((int) $exception->center_id === (int) $center->id, 404);
        $exception->delete();

        return response()->json(['status' => true, 'message' => 'Excepción borrada.']);
    }

    /** §4.1: los patrones recurrentes de los operadores del centro (12/4 u otro) y los cambios por fecha. */
    public function calendarSetup(LogisticsCenter $center)
    {
        return response()->json([
            'status' => true,
            'patterns' => OperatorPattern::with('user:id,names')->where('center_id', $center->id)->orderByDesc('is_active')->orderBy('id')->get(),
            'overrides' => OperatorOverride::with('user:id,names')->where('center_id', $center->id)->whereDate('date', '>=', now()->subDays(30)->toDateString())
                ->orderBy('date')->get(),
            'reasons' => OperatorOverride::REASONS,
            'operators' => $center->operators()->get(['users.id', 'users.names'])->map(fn ($u) => ['id' => $u->id, 'names' => $u->names, 'coverage' => $u->pivot->coverage]),
        ]);
    }

    /** §4.1: "Soportar patrones recurrentes, incluido 12 días trabajados / 4 días libres", desde una fecha. */
    public function storePattern(Request $request, LogisticsCenter $center)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('logistics_center_operators', 'user_id')->where('center_id', $center->id)],
            'starts_on' => 'required|date',
            'days_on' => 'required|integer|min:1|max:60',
            'days_off' => 'required|integer|min:0|max:60',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
        ], ['user_id.exists' => 'Ese usuario no es operador de este centro.']);
        $pattern = OperatorPattern::create($data + ['center_id' => $center->id, 'is_active' => true, 'created_by' => Auth::id()]);

        return response()->json(['status' => true, 'message' => 'Patrón guardado.', 'pattern' => $pattern]);
    }

    /** Se apaga, no se borra: el historial de quién estaba programado sigue (§4.1). */
    public function disablePattern(LogisticsCenter $center, OperatorPattern $pattern)
    {
        abort_unless((int) $pattern->center_id === (int) $center->id, 404);
        $pattern->update(['is_active' => false]);

        return response()->json(['status' => true, 'message' => 'Patrón apagado.']);
    }

    /** §4.1: "sustituciones manuales por fecha, vacaciones, ausencias y cambios de último minuto". Uno por día. */
    public function storeOverride(Request $request, LogisticsCenter $center)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'user_id' => ['nullable', 'integer', Rule::exists('logistics_center_operators', 'user_id')->where('center_id', $center->id)],
            'reason' => ['required', Rule::in(array_keys(OperatorOverride::REASONS))],
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'note' => 'nullable|string|max:255',
        ], ['user_id.exists' => 'Ese usuario no es operador de este centro.']);
        $override = OperatorOverride::updateOrCreate(['center_id' => $center->id, 'date' => $data['date']],
            collect($data)->except('date')->all() + ['user_id' => null, 'created_by' => Auth::id()]);

        return response()->json(['status' => true, 'message' => 'Cambio del día guardado.', 'override' => $override]);
    }

    public function destroyOverride(LogisticsCenter $center, OperatorOverride $override)
    {
        abort_unless((int) $override->center_id === (int) $center->id, 404);
        $override->delete();

        return response()->json(['status' => true, 'message' => 'Cambio del día borrado: vale el patrón.']);
    }

    /** Parámetros vigentes, de dónde salen (del centro o generales) y sus versiones anteriores (§33). */
    public function settings(Request $request)
    {
        $center = $request->filled('center_id') ? LogisticsCenter::findOrFail($request->integer('center_id')) : null;
        $version = $this->settings->version($center);

        return response()->json([
            'status' => true,
            'values' => $this->settings->for($center),
            'source' => $version?->center_id ? 'centro' : 'general',
            'version' => $version?->only(['id', 'valid_from', 'created_by']),
            'history' => LogisticsSetting::with('creator:id,names')
                ->when($center, fn ($q) => $q->whereIn('center_id', [$center->id]), fn ($q) => $q->whereNull('center_id'))
                ->orderByDesc('valid_from')->limit(30)->get(['id', 'center_id', 'values', 'valid_from', 'created_by']),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'center_id' => 'nullable|integer|exists:logistics_centers,id',
            'values' => 'required|array',
        ]);
        $center = isset($data['center_id']) ? LogisticsCenter::find($data['center_id']) : null;
        $version = $this->settings->update($center, $data['values'], Auth::user());

        return response()->json(['status' => true, 'message' => 'Parámetros guardados: valen desde ahora.', 'values' => $version->values]);
    }

    private function validated(Request $request, ?LogisticsCenter $center = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'city_id' => ['required', 'integer', 'exists:cities,id', Rule::unique('logistics_centers', 'city_id')->ignore($center?->id)],
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'address' => 'nullable|string|max:255',
            'dispatch_cutoff' => 'nullable|date_format:H:i',
            'opening_hours' => 'nullable|array',
            'opening_hours.*' => 'nullable|array|size:2',
            'opening_hours.*.*' => 'date_format:H:i',
        ]);
        foreach ($data['opening_hours'] ?? [] as $day => $hours) {
            if (!in_array((string) $day, ['1', '2', '3', '4', '5', '6', '7'], true)) {
                abort(response()->json(['status' => false, 'message' => 'Los días van del 1 (lunes) al 7 (domingo).'], 422));
            }
            if ($hours && $hours[0] >= $hours[1]) {
                abort(response()->json(['status' => false, 'message' => 'La hora de cierre tiene que ser después de la de apertura.'], 422));
            }
        }

        return $data;
    }

    private function present(LogisticsCenter $center): array
    {
        $center->loadMissing(['city:id,name', 'warehouse:id,name,maps_url,latitude,longitude', 'operators:id,names,email', 'activeShift.user:id,names']);
        $state = $this->centers->state($center);

        return $center->toArray() + [
            'state' => $state,
            'state_label' => Centers::STATE_LABELS[$state],
            'today_hours' => $this->centers->hoursOn($center, now()),
            'activation_problems' => $this->centers->activationProblems($center),
            'exceptions_upcoming' => $center->exceptions()->whereDate('date', '>=', now()->toDateString())->orderBy('date')->limit(20)->get(),
        ];
    }
}
