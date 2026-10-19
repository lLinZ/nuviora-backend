<?php

namespace App\Http\Controllers;

use App\Services\Replenishment\PlanApproval;
use App\Services\Replenishment\PlanEvaluator;
use App\Services\Replenishment\PlanRoutePdf;
use App\Services\Replenishment\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reabastecimiento › Plan (Módulo 5 de Fran, entregas 3 y 4). Solo Admin/propietario (§3): el grupo de rutas de Finanzas
 * ya lo exige. "Generar plan" con la cobertura objetivo (§4); cada plan queda guardado (§96). Entrega 4: aprobar el plan
 * (§89–§92), el PDF de ruta del chofer (§112–§120) y la precisión de los planes que ya terminaron (§97–§105).
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

    /** §89: aprobar todo o en parte, con las cantidades, el proveedor, el costo y el origen/destino que el Admin decida */
    public function approve(Request $request, int $forecast, PlanApproval $approval): JsonResponse
    {
        $data = $request->validate([
            'purchases' => 'present|array',
            'purchases.*.id' => 'required|integer',
            'purchases.*.qty' => 'required|integer|min:0|max:100000',
            'purchases.*.supplier_id' => 'nullable|integer',
            'purchases.*.receiving_warehouse_id' => 'nullable|integer',
            'purchases.*.unit_cost_usd' => 'nullable|numeric|min:0',
            'purchases.*.expected_at' => 'nullable|date',
            'transfers' => 'present|array',
            'transfers.*.id' => 'required|integer',
            'transfers.*.qty' => 'required|integer|min:0|max:100000',
            'transfers.*.from_warehouse_id' => 'nullable|integer',
            'transfers.*.to_warehouse_id' => 'nullable|integer',
        ], [
            'purchases.*.qty.min' => 'Las cantidades no pueden ser negativas.',
            'transfers.*.qty.min' => 'Las cantidades no pueden ser negativas.',
        ]);
        $summary = $approval->approve($forecast, $data['purchases'], $data['transfers']);

        return response()->json(['message' => 'Plan aprobado.', 'summary' => $summary, 'plan' => $this->plans->show($forecast)]);
    }

    /** §112: el PDF de ruta del chofer, solo de un plan aprobado */
    public function routePdf(int $forecast, PlanRoutePdf $pdf): Response
    {
        $content = $pdf->pdf($forecast);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ruta-plan-' . $forecast . '-' . date('Y-m-d') . '.pdf"',
        ]);
    }

    /** §98–§104: la precisión de los planes que ya terminaron su horizonte */
    public function evaluations(PlanEvaluator $evaluator): JsonResponse
    {
        return response()->json(['evaluations' => $evaluator->history(), 'pending' => count($evaluator->due())]);
    }
}
