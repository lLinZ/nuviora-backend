<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeliveredOrdersReportController extends Controller
{
    public function index(Request $request)
    {
        // Admin only
        $user = Auth::user();
        if ($user->role?->description !== 'Admin') {
            return response()->json(['status' => false, 'message' => 'No autorizado.'], 403);
        }

        $orders = Order::with(['client', 'agent', 'agency', 'status'])
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

                return [
                    'id'             => $order->id,
                    'order_number'   => $order->name ?? $order->order_number,
                    'status'         => $order->status?->description ?? 'Sin estatus',
                    'client_name'    => $clientName,
                    'client_phone'   => $order->client?->phone ?? '',
                    'agent_name'     => $order->agent?->names ?? 'Sin vendedora',
                    'agency_name'    => $order->agency?->names ?? 'Sin agencia',
                    'total'          => $order->current_total_price,
                    'currency'       => $order->currency ?? 'USD',
                    'created_at'     => $createdAt?->format('Y-m-d H:i:s'),
                    'processed_at'   => $processedAt?->format('Y-m-d H:i:s'),
                    'duration_hours' => $durationHours,
                ];
            });

        return response()->json([
            'status' => true,
            'data'   => $orders,
            'total'  => $orders->count(),
        ]);
    }
}

