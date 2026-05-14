<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /reports/orders-export?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Returns all orders created within the given date range,
 * with ALL relevant fields for Excel/ChatGPT analysis.
 */
class OrdersExportController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!in_array($user?->role?->description, ['Admin', 'Gerente'])) {
            return response()->json(['status' => false, 'message' => 'No autorizado.'], 403);
        }

        $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to'   => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);

        $from = Carbon::parse($request->from)->startOfDay();
        $to   = Carbon::parse($request->to)->endOfDay();

        $orders = Order::with([
                'client',
                'agent',
                'agency',
                'deliverer',
                'status',
                'city',
                'province',
                'products',
                'payments',
                'cancellations',
                'postponements',
            ])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                $clientName = $order->client
                    ? trim(($order->client->first_name ?? '') . ' ' . ($order->client->last_name ?? ''))
                    : 'Sin cliente';

                $createdAt   = $order->created_at   ? Carbon::parse($order->created_at)   : null;
                $processedAt = $order->processed_at ? Carbon::parse($order->processed_at) : null;
                $cancelledAt = $order->cancelled_at  ? Carbon::parse($order->cancelled_at)  : null;
                $scheduledFor = $order->scheduled_for ? Carbon::parse($order->scheduled_for) : null;

                $durationHours = ($createdAt && $processedAt)
                    ? round($createdAt->diffInMinutes($processedAt) / 60, 2)
                    : null;

                // Products summary: "Producto A x2, Producto B x1"
                $productsSummary = $order->products->map(function ($p) {
                    $name = $p->showable_name ?? $p->title ?? $p->name ?? 'Producto';
                    return "{$name} x{$p->quantity}";
                })->implode(', ');

                // Individual product lines for detail
                $productsDetail = $order->products->map(function ($p) {
                    return [
                        'name'       => $p->showable_name ?? $p->title ?? $p->name,
                        'quantity'   => (int) $p->quantity,
                        'price'      => (float) $p->price,
                        'is_upsell'  => (bool) $p->is_upsell,
                    ];
                });

                // Payment details
                $paymentsDetail = $order->payments->map(fn($p) => [
                    'method'   => $p->payment_method ?? '',
                    'amount'   => (float) $p->amount,
                    'currency' => $p->currency ?? 'USD',
                ]);

                $isCancelled    = !is_null($cancelledAt) || strtolower($order->status?->description ?? '') === 'cancelado';
                $isDelivered    = strtolower($order->status?->description ?? '') === 'entregado';
                $postponeCount  = $order->postponements->count();

                return [
                    // ── Identificadores ──────────────────────────────────────
                    'id'                  => $order->id,
                    'order_number'        => $order->name ?? $order->order_number,

                    // ── Estatus ───────────────────────────────────────────────
                    'status'              => $order->status?->description ?? 'Sin estatus',
                    'is_delivered'        => $isDelivered,
                    'is_cancelled'        => $isCancelled,
                    'is_return'           => (bool) $order->is_return,
                    'is_exchange'         => (bool) $order->is_exchange,

                    // ── Cliente ───────────────────────────────────────────────
                    'client_name'         => $clientName,
                    'client_phone'        => $order->client?->phone ?? '',

                    // ── Geografía ─────────────────────────────────────────────
                    'city'                => $order->city?->name ?? '',
                    'province'            => $order->province?->name ?? '',

                    // ── Equipo ────────────────────────────────────────────────
                    'agent_name'          => $order->agent?->names ?? 'Sin vendedora',
                    'agency_name'         => $order->agency?->names ?? 'Sin agencia',
                    'deliverer_name'      => $order->deliverer?->names ?? 'Sin repartidor',

                    // ── Productos ─────────────────────────────────────────────
                    'products_summary'    => $productsSummary,
                    'products_count'      => $order->products->count(),
                    'products_qty_total'  => $order->products->sum('quantity'),
                    'has_upsell'          => $order->products->where('is_upsell', true)->count() > 0,
                    'products'            => $productsDetail,

                    // ── Financiero ────────────────────────────────────────────
                    'total'               => (float) $order->current_total_price,
                    'currency'            => $order->currency ?? 'USD',
                    'payment_method'      => $order->payment_method ?? '',
                    'delivery_cost'       => (float) ($order->delivery_cost ?? 0),
                    'cash_received'       => (float) ($order->cash_received ?? 0),
                    'change_amount'       => (float) ($order->change_amount ?? 0),
                    'payments'            => $paymentsDetail,

                    // ── Fechas y tiempos ──────────────────────────────────────
                    'created_at'          => $createdAt?->format('Y-m-d H:i:s'),
                    'processed_at'        => $processedAt?->format('Y-m-d H:i:s'),
                    'cancelled_at'        => $cancelledAt?->format('Y-m-d H:i:s'),
                    'scheduled_for'       => $scheduledFor?->format('Y-m-d H:i:s'),
                    'created_weekday'     => $createdAt?->locale('es')->dayName,
                    'created_hour'        => $createdAt?->format('H'),
                    'duration_hours'      => $durationHours,

                    // ── Comportamiento ────────────────────────────────────────
                    'postpone_count'      => $postponeCount,
                    'novedad_type'        => $order->novedad_type ?? '',
                ];
            });

        return response()->json([
            'status' => true,
            'from'   => $from->format('Y-m-d'),
            'to'     => $to->format('Y-m-d'),
            'total'  => $orders->count(),
            'data'   => $orders,
        ]);
    }
}
