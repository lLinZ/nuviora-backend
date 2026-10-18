<?php

namespace App\Http\Controllers;

use App\Services\Replenishment\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reabastecimiento › Plan (Módulo 5 de Fran, entrega 3). Solo Admin/propietario (§3): el grupo de rutas de Finanzas ya
 * lo exige. "Generar plan" con la cobertura objetivo (§4); cada plan queda guardado (§96).
 */
class ReplenishmentPlanController extends Controller
{
    public function __construct(private PlanService $plans)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['plans' => $this->plans->history()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['coverage_days' => 'nullable|numeric|min:1|max:90'], [
            'coverage_days.min' => 'La cobertura tiene que ser de al menos 1 día.',
            'coverage_days.max' => 'La cobertura máxima es de 90 días.',
        ]);
        $plan = $this->plans->generate(isset($data['coverage_days']) ? (float) $data['coverage_days'] : null);

        return response()->json(['message' => 'Plan generado.', 'plan' => $plan], 201);
    }

    public function show(int $forecast): JsonResponse
    {
        return response()->json(['plan' => $this->plans->show($forecast)]);
    }
}
