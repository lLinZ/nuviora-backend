<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Orders\ChangeRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Vueltos de la agencia por validar (Fran, 2026-10-03): el cliente pagó una parte en efectivo y otra digital,
 * y la agencia da vuelto. Administración lo valida o lo rechaza antes de que la orden siga.
 */
class OrderChangeApprovalController extends Controller
{
    private const CLOSED = ['Entregado', 'Cancelado', 'Rechazado'];

    public function index()
    {
        $orders = Order::query()
            ->whereIn('change_covered_by', ['agency', 'partial'])
            ->where('change_amount_agency', '>', 0)
            ->whereHas('payments', fn ($q) => $q->whereIn('method', ChangeRules::CASH_METHODS))
            ->whereHas('payments', fn ($q) => $q->whereNotIn('method', ChangeRules::CASH_METHODS))
            ->whereDoesntHave('status', fn ($q) => $q->whereIn('description', self::CLOSED))
            ->with(['status:id,description', 'agent:id,names,surnames', 'agency:id,names', 'client:id,first_name,last_name', 'payments:id,order_id,method,amount', 'shop:id,name'])
            ->orderBy('updated_at')
            ->get()
            ->filter(fn (Order $o) => ChangeRules::needsApproval($o))
            ->values()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'status' => $o->status?->description,
                'shop' => $o->shop?->name,
                'agent' => $o->agent ? trim($o->agent->names . ' ' . ($o->agent->surnames === '-' ? '' : $o->agent->surnames)) : null,
                'agency' => $o->agency?->names,
                'client' => trim(($o->client->first_name ?? '') . ' ' . ($o->client->last_name ?? '')),
                'total' => (float) $o->current_total_price,
                'payments' => $o->payments->map(fn ($p) => [
                    'method' => $p->method,
                    'amount' => (float) $p->amount,
                    'is_cash' => in_array($p->method, ChangeRules::CASH_METHODS, true),
                ])->values(),
                'change_amount' => (float) $o->change_amount,
                'change_covered_by' => $o->change_covered_by,
                'change_amount_agency' => (float) $o->change_amount_agency,
                'change_method_agency' => $o->change_method_agency,
                'change_amount_company' => (float) $o->change_amount_company,
                'updated_at' => $o->updated_at?->toDateTimeString(),
            ]);

        return response()->json(['status' => true, 'orders' => $orders]);
    }

    public function decide(Request $request, Order $order)
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'reason' => 'nullable|string|max:300',
        ]);

        if (!ChangeRules::approvalState($order)['required']) {
            return response()->json([
                'status' => false,
                'message' => 'Esta orden ya no tiene vuelto de la agencia con pago mixto. Recarga la lista.',
            ], 422);
        }

        if ($data['decision'] === 'approve') {
            ChangeRules::approve($order, Auth::user());
            $message = "Vuelto de {$order->name} validado.";
        } else {
            ChangeRules::reject($order, Auth::user(), $data['reason'] ?? null);
            $message = "Vuelto de {$order->name} rechazado: la vendedora tiene que corregirlo.";
        }

        return response()->json(['status' => true, 'message' => $message, 'change_approval' => ChangeRules::approvalState($order->fresh())]);
    }
}
