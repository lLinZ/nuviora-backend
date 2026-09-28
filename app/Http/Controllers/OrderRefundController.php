<?php

namespace App\Http\Controllers;

use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\OrderActivityLog;
use App\Models\OrderRefund;
use App\Models\OrderUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Devolución = reembolso (tarea 3b, Fran §7). Se le transfiere el dinero al cliente y el producto se
 * queda con él: no hay orden hija, carrera ni movimiento de stock, y la vendedora conserva su comisión.
 * La orden queda marcada con orders.refunded_usd. El acceso a la orden lo cuida el middleware order.access.
 */
class OrderRefundController extends Controller
{
    private const ROLES = ['Admin', 'Gerente', 'Master', 'Vendedor'];

    public function index(Order $order): JsonResponse
    {
        $refunds = $order->refunds()->with(['user:id,names', 'order:id,name'])->orderBy('refunded_at')->orderBy('id')->get();

        return response()->json([
            'status' => true,
            'data' => [
                'refunds' => $refunds->map->toPayload()->values(),
                'refunded_usd' => (float) $order->refunded_usd,
                'refundable_usd' => $this->refundable($order),
                'methods' => OrderRefund::METHODS,
            ],
        ]);
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        abort_unless(in_array(Auth::user()->role?->description, self::ROLES, true), 403, 'No tienes permiso para registrar reembolsos.');
        if ($order->is_return || $order->is_exchange) {
            return response()->json(['status' => false, 'message' => 'Los reembolsos se registran en la orden original, no en un cambio.'], 422);
        }
        if ($order->status?->description !== OrderStatus::ENTREGADO) {
            return response()->json(['status' => false, 'message' => 'Solo se pueden reembolsar órdenes entregadas.'], 422);
        }

        $refundable = $this->refundable($order);
        $data = $request->validate([
            'amount_usd' => ['required', 'numeric', 'min:0.01', 'max:' . max(0.01, $refundable)],
            'method' => ['nullable', 'string', Rule::in(OrderRefund::METHODS)],
            'refunded_at' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ], [
            'amount_usd.max' => 'El reembolso no puede superar lo que queda por devolver ($' . number_format($refundable, 2) . ').',
            'refunded_at.before_or_equal' => 'La fecha del reembolso no puede ser futura.',
        ]);
        if ($refundable <= 0) {
            return response()->json(['status' => false, 'message' => 'Esta orden ya se reembolsó completa.'], 422);
        }

        $path = $request->hasFile('receipt') ? $request->file('receipt')->store('refunds', 'local') : null;
        try {
            $refund = DB::transaction(function () use ($order, $data, $path) {
                $refund = OrderRefund::create([
                    'order_id' => $order->id,
                    'amount_usd' => round((float) $data['amount_usd'], 2),
                    'method' => $data['method'] ?? null,
                    'refunded_at' => $data['refunded_at'] ?? now()->toDateString(),
                    'notes' => $data['notes'] ?? null,
                    'receipt_path' => $path,
                    'user_id' => Auth::id(),
                ]);
                $order->forceFill(['refunded_usd' => round((float) $order->refunded_usd + $refund->amount_usd, 2)])->saveQuietly();

                return $refund;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        $text = 'Reembolso de $' . number_format($refund->amount_usd, 2) . ($refund->method ? " por {$refund->method}" : '')
            . '. El producto se queda con el cliente; no se mueve stock ni se quita la comisión.';
        OrderActivityLog::create(['order_id' => $order->id, 'user_id' => Auth::id(), 'action' => 'refund', 'description' => $text, 'properties' => ['refund_id' => $refund->id]]);
        OrderUpdate::create(['order_id' => $order->id, 'user_id' => Auth::id(), 'message' => "💸 {$text}"]);

        return response()->json([
            'status' => true,
            'message' => 'Reembolso registrado',
            'data' => ['refund' => $refund->load('user:id,names', 'order:id,name')->toPayload(), 'refunded_usd' => (float) $order->fresh()->refunded_usd],
        ]);
    }

    public function receipt(Order $order, OrderRefund $refund)
    {
        abort_unless($refund->order_id === $order->id && $refund->receipt_path, 404);
        abort_unless(Storage::disk('local')->exists($refund->receipt_path), 404, 'El comprobante ya no está.');

        return Storage::disk('local')->download($refund->receipt_path, "reembolso-{$order->name}-{$refund->id}." . pathinfo($refund->receipt_path, PATHINFO_EXTENSION));
    }

    /** Lo que se puede devolver: lo cobrado menos lo ya reembolsado. */
    private function refundable(Order $order): float
    {
        return round(max(0, (float) $order->current_total_price - (float) $order->refunded_usd), 2);
    }
}
