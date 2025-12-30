<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderLocationReview;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderLocationReviewController extends Controller
{
    public function approve(Request $request, OrderLocationReview $review)
    {
        $this->ensureManagerOrAdmin();

        if ($review->status !== 'pending') {
            return response()->json(['status' => false, 'message' => 'Esta solicitud ya no está pendiente'], 422);
        }

        $review->status = 'approved';
        $review->reviewed_by = Auth::id();
        $review->reviewed_at = now();
        $review->response_note = $request->response_note;
        $review->save();

        // Aplicar el cambio de ubicación a la orden
        $order = $review->order;
        if ($review->new_location) {
            // Asumiendo que tenemos un campo 'location' en la tabla 'orders' o lo manejamos vía actualizaciones
            // Si el usuario simplemente quiere cambiar el status a "Cambio de ubicación"
            $statusCambio = Status::where('description', 'Cambio de ubicacion')->first();
            if ($statusCambio) {
                $order->status_id = $statusCambio->id;
                $order->save();
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Cambio de ubicación aprobado ✅',
            'order' => $order->load(['status', 'locationReviews']),
        ]);
    }

    public function reject(Request $request, OrderLocationReview $review)
    {
        $this->ensureManagerOrAdmin();

        if ($review->status !== 'pending') {
            return response()->json(['status' => false, 'message' => 'Esta solicitud ya no está pendiente'], 422);
        }

        $review->status = 'rejected';
        $review->reviewed_by = Auth::id();
        $review->reviewed_at = now();
        $review->response_note = $request->response_note;
        $review->save();

        // Devolver la orden a su estado anterior o simplemente quitar el bloqueo
        // Por ahora, solo quitamos el estado "Por aprobar cambio de ubicacion"
        // Podríamos necesitar guardar el estado original si es muy variable.
        // Pero usualmente se vuelve a "Nuevo" o se queda en el que estaba si no se cambió.

        return response()->json([
            'status' => true,
            'message' => 'Cambio de ubicación rechazado ❌',
            'order' => $review->order->load(['status', 'locationReviews']),
        ]);
    }

    private function ensureManagerOrAdmin()
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Gerente', 'Admin'])) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }
    }
}
