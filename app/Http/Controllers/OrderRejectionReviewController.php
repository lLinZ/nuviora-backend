<?php

namespace App\Http\Controllers;

use App\Models\OrderRejectionReview;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderRejectionReviewController extends Controller
{
    public function approve(Request $request, OrderRejectionReview $review)
    {
        $this->ensureManagerOrAdmin();

        if ($review->status !== 'pending') {
            return response()->json(['status' => false, 'message' => 'Esta solicitud ya no está pendiente'], 422);
        }

        $review->status = 'approved';
        $review->response_note = $request->response_note;
        $review->save();

        // Aplicar status "Rechazado"
        $statusRechazado = Status::where('description', 'Rechazado')->first();
        $order = $review->order;
        
        if ($statusRechazado) {
            $order->status_id = $statusRechazado->id;
            $order->save();
        }

        return response()->json([
            'status' => true,
            'message' => 'Rechazo aprobado y aplicado ✅',
            'order' => $order->load(['status', 'rejectionReviews']),
        ]);
    }

    public function reject(Request $request, OrderRejectionReview $review)
    {
        $this->ensureManagerOrAdmin();

        if ($review->status !== 'pending') {
            return response()->json(['status' => false, 'message' => 'Esta solicitud ya no está pendiente'], 422);
        }

        $review->status = 'rejected';
        $review->response_note = $request->response_note;
        $review->save();

        // Si se rechaza el rechazo, ¿a qué estado vuelve?
        // Debería volver a su estado válido anterior, pero quizás solo "Confirmado" o "Nuevo" o "Asignado"?
        // Por simplificación, podríamos no cambiar el estado si ya tenía uno "Por aprobar rechazo".
        // Sin embargo, si está en 'Por aprobar rechazo', y se rechaza esa petición, el orden debería volver a un estado operativo.
        // Asumamos que vuelve a "Confirmado" o lo dejamos en manos del Manager cambiarlo manualmente luego.
        // Pero para UI feedback, mejor regresarlo a algo neutro like "Confirmado" if possible, or force manual change.
        // Si no hacemos nada, se queda en "Por aprobar rechazo" pero con reviewrejected, lo cual es incoinherente.
        
        // Estrategia: Buscar "Confirmado" o dejarlo.
        // Safer: Switch to "Confirmado" default fallback.
        $statusConfirmado = Status::where('description', 'Confirmado')->first();
        $order = $review->order;
        if ($statusConfirmado) {
            $order->status_id = $statusConfirmado->id;
            $order->save();
        }

        return response()->json([
            'status' => true,
            'message' => 'Solicitud de rechazo denegada ❌ Orden vuelve a Confirmado',
            'order' => $order->load(['status', 'rejectionReviews']),
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
