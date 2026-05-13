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
 * with all relevant fields for Excel export.
 */
class OrdersExportController extends Controller
{
    public function index(Request $request)
    {
        // Admin / Gerente only
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

                $durationHours = ($createdAt && $processedAt)
                    ? round($createdAt->diffInMinutes($processedAt) / 60, 2)
                    : null;

                // Products summary: "Producto A x2, Producto B x1"
                $productsSummary = $order->products->map(function ($p) {
                    $name = $p->showable_name ?? $p->title ?? $p->name ?? 'Producto';
                    return "{$name} x{$p->quantity}";
                })->implode(', ');

                // Individual products as structured data
                $productsDetail = $order->products->map(function ($p) {
                    return [
                        'name'     => $p->showable_name ?? $p->title ?? $p->name,
                        'quantity' => (int) $p->quantity,
                        'price'    => (float) $p->price,
                    ];
                });

                return [
                    'id'              => $order->id,
                    'order_number'    => $order->name ?? $order->order_number,
                    'status'          => $order->status?->description ?? 'Sin estatus',
                    'client_name'     => $clientName,
                    'client_phone'    => $order->client?->phone ?? '',
                    'city'            => $order->city?->name ?? '',
                    'province'        => $order->province?->name ?? '',
                    'agent_name'      => $order->agent?->names ?? 'Sin vendedora',
                    'agency_name'     => $order->agency?->names ?? 'Sin agencia',
                    'deliverer_name'  => $order->deliverer?->names ?? 'Sin repartidor',
                    'products_summary'=> $productsSummary,
                    'products'        => $productsDetail,
                    'total'           => (float) $order->current_total_price,
                    'currency'        => $order->currency ?? 'USD',
                    'payment_method'  => $order->payment_method ?? '',
                    'created_at'      => $createdAt?->format('Y-m-d H:i:s'),
                    'processed_at'    => $processedAt?->format('Y-m-d H:i:s'),
                    'scheduled_for'   => $order->scheduled_for
                                            ? Carbon::parse($order->scheduled_for)->format('Y-m-d H:i:s')
                                            : null,
                    'duration_hours'  => $durationHours,
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
