<?php
// app/Http/Controllers/OrderCancellationReviewController.php
namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderCancellation;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderCancellationReviewController extends Controller
{
    // Solo Gerente/Admin deberían usar estos endpoints (puedes validar rol aquí)
    protected function ensureManagerOrAdmin(): void
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Gerente', 'Admin'])) {
            abort(403, 'No autorizado');
        }
    }

    // GET /orders/cancellations?status=pending
    public function index(Request $request)
    {
        $this->ensureManagerOrAdmin();

        $status = $request->get('status', 'pending'); // pending|approved|rejected
        $list = OrderCancellation::with(['order.client', 'order.status', 'user', 'reviewer'])
            ->where('status', $status)
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'status' => true,
            'data'  => $list->items(),
            'meta'  => [
                'current_page' => $list->currentPage(),
                'total'        => $list->total(),
                'last_page'    => $list->lastPage(),
            ],
        ]);
    }

    // PUT /orders/cancellations/{cancellation}/approve
    public function approve(Request $request, OrderCancellation $cancellation)
    {
        $this->ensureManagerOrAdmin();

        $request->validate(['response_note' => 'nullable|string|max:1000']);

        if ($cancellation->status !== 'pending') {
            return response()->json(['status' => false, 'message' => 'La solicitud no está pendiente'], 422);
        }

        $cancelledId = Status::where('description', 'Cancelado')->value('id');

        $cancellation->update([
            'status'       => 'approved',
            'reviewed_by'  => Auth::id(),
            'reviewed_at'  => now(),
            'response_note' => $request->response_note,
        ]);

        $order = $cancellation->order;
        $order->update([
            'status_id'   => $cancelledId,
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Cancelación aprobada',
            'order'  => $order->load('client', 'agent', 'status', 'cancellations'),
            'cancellation' => $cancellation->fresh(['reviewer'])
        ]);
    }

    // PUT /orders/cancellations/{cancellation}/reject
    public function reject(Request $request, OrderCancellation $cancellation)
    {
        $this->ensureManagerOrAdmin();

        $request->validate(['response_note' => 'nullable|string|max:1000']);

        if ($cancellation->status !== 'pending') {
            return response()->json(['status' => false, 'message' => 'La solicitud no está pendiente'], 422);
        }

        // Revertimos la orden a su status previo
        $order = $cancellation->order;
        $order->update([
            'status_id' => $cancellation->previous_status_id,
        ]);

        $cancellation->update([
            'status'       => 'rejected',
            'reviewed_by'  => Auth::id(),
            'reviewed_at'  => now(),
            'response_note' => $request->response_note,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Cancelación rechazada',
            'order'  => $order->load('client', 'agent', 'status', 'cancellations'),
            'cancellation' => $cancellation->fresh(['reviewer'])
        ]);
    }
}
