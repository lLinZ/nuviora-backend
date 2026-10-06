<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzePaymentReceipt;
use App\Models\Order;
use App\Models\OrderUpdate;
use App\Models\ReceiptCheck;
use App\Services\Payments\ReceiptChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Revisión de comprobantes con IA (Fran, 2026-10-03): lo que ve cada orden, la lista de Fran con lo que no
 * cuadra, y la aprobación a mano cuando la IA se equivoca.
 */
class ReceiptCheckController extends Controller
{
    /** Las revisiones de los comprobantes de una orden (la pantalla las pide mientras están pendientes). */
    public function forOrder(Order $order)
    {
        $checks = ReceiptCheck::where('order_id', $order->id)->with('reviewer:id,names,surnames')->get()
            ->map(fn (ReceiptCheck $c) => $this->present($c));

        return response()->json([
            'status' => true,
            'mode' => ReceiptChecker::mode(),
            'checks' => $checks,
            'delivery_block' => app(ReceiptChecker::class)->deliveryBlockMessage($order),
        ]);
    }

    /** Lo que Fran tiene que revisar: no cuadra, no se lee, dudoso o no se pudo revisar, de las últimas 2 semanas. */
    public function index(Request $request)
    {
        $statuses = [ReceiptCheck::FAIL, ReceiptCheck::UNREADABLE, ReceiptCheck::WARNING, ReceiptCheck::ERROR];
        $checks = ReceiptCheck::whereIn('status', $statuses)
            ->whereNull('reviewed_at')
            ->where('created_at', '>=', now()->subDays(14))
            ->whereHas('order', fn ($q) => $q->whereDoesntHave('status', fn ($s) => $s->whereIn('description', ['Cancelado', 'Rechazado'])))
            ->with(['order:id,name,status_id,agent_id,agency_id,current_total_price', 'order.status:id,description', 'order.agent:id,names', 'order.agency:id,names', 'receipt'])
            ->orderByRaw("FIELD(status, 'fail', 'unreadable', 'error', 'warning')")
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'status' => true,
            'mode' => ReceiptChecker::mode(),
            'checks' => $checks->map(fn (ReceiptCheck $c) => $this->present($c) + [
                'order' => [
                    'id' => $c->order->id,
                    'name' => $c->order->name,
                    'status' => $c->order->status?->description,
                    'agent' => $c->order->agent?->names,
                    'agency' => $c->order->agency?->names,
                    'total' => (float) $c->order->current_total_price,
                ],
                'receipt_url' => $c->receipt?->url,
            ]),
        ]);
    }

    /** Fran lo da por bueno: ya no bloquea la entrega. Queda una nota en la orden. */
    public function approve(Request $request, Order $order, ReceiptCheck $check)
    {
        abort_unless($check->order_id === $order->id, 404);
        $data = $request->validate(['note' => 'nullable|string|max:300']);
        $check->forceFill(['reviewed_by' => Auth::id(), 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null])->save();
        OrderUpdate::create([
            'order_id' => $order->id,
            'user_id' => Auth::id(),
            'message' => 'Administración aprobó a mano un comprobante que la revisión marcó.' . (!empty($data['note']) ? " Nota: {$data['note']}" : ''),
        ]);

        return response()->json(['status' => true, 'message' => 'Comprobante aprobado.', 'check' => $this->present($check->fresh('reviewer'))]);
    }

    /** Vuelve a leer el comprobante (por ejemplo, si la IA estaba caída). */
    public function retry(Order $order, ReceiptCheck $check)
    {
        abort_unless($check->order_id === $order->id, 404);
        if (ReceiptChecker::mode() === 'off') {
            return response()->json(['status' => false, 'message' => 'La revisión de comprobantes está apagada.'], 422);
        }
        if (!in_array($check->status, [ReceiptCheck::ERROR, ReceiptCheck::UNREADABLE], true) && !in_array(Auth::user()->role?->description, ['Admin', 'Gerente'], true)) {
            return response()->json(['status' => false, 'message' => 'Solo se puede volver a revisar si hubo un error o no se leía.'], 422);
        }
        AnalyzePaymentReceipt::forReceipt($check->receipt);

        return response()->json(['status' => true, 'message' => 'Revisando otra vez…']);
    }

    private function present(ReceiptCheck $c): array
    {
        $d = $c->extracted ?? [];

        return [
            'id' => $c->id,
            'payment_receipt_id' => $c->payment_receipt_id,
            'status' => $c->status,
            'kind' => $c->kind,
            'kind_label' => $c->kind ? (ReceiptChecker::KIND_LABEL[$c->kind] ?? $c->kind) : null,
            'issues' => $c->issues ?? [],
            'error' => $c->status === ReceiptCheck::ERROR ? 'No se pudo revisar el comprobante. Se puede intentar otra vez.' : null,
            'summary' => array_filter([
                'banco' => $d['banco_origen'] ?? null,
                'banco_destino' => $d['banco_destino'] ?? null,
                // De una foto de billetes no se muestra cuánto contó la IA (2026-10-06): los cuenta mal
                'monto' => $c->kind === 'efectivo' ? null : $c->amount,
                'moneda' => $c->currency,
                'referencia' => $d['referencia'] ?? null,
                'fecha' => $d['fecha'] ?? null,
                'hora' => $d['hora'] ?? null,
                'telefono_destino' => $d['receptor_telefono'] ?? null,
                'cedula_destino' => $d['receptor_identificacion'] ?? null,
                'correo_destino' => $d['receptor_correo'] ?? null,
                'confianza' => $d['confianza'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'approved' => $c->isApproved(),
            'approved_by' => $c->reviewer ? trim($c->reviewer->names . ' ' . ($c->reviewer->surnames === '-' ? '' : $c->reviewer->surnames)) : null,
            'approved_at' => $c->reviewed_at?->toDateTimeString(),
            'review_note' => $c->review_note,
            'created_at' => $c->created_at?->toDateTimeString(),
        ];
    }
}
