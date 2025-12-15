<?php

namespace App\Http\Controllers;

use App\Models\OrderDeliveryReview;
use App\Models\Status;
use App\Services\CommissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderDeliveryReviewController extends Controller
{
    public function approve(Request $request, OrderDeliveryReview $review, CommissionService $commissionService)
    {
        $user = Auth::user();
        if (!in_array($user->role?->description, ['Gerente', 'Admin'])) {
            return response()->json(['status' => false, 'message' => 'No tienes permiso para aprobar'], 403);
        }

        if ($review->status !== 'pending') {
            return response()->json(['status' => false, 'message' => 'Solicitud ya procesada'], 422);
        }

        $request->validate(['response_note' => 'nullable|string|max:1000']);

        $statusEntregado = Status::where('description', 'Entregado')->firstOrFail();

        // Actualizar review
        $review->update([
            'status' => 'approved',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'response_note' => $request->response_note,
        ]);

        // Actualizar Orden
        $order = $review->order;
        $order->status_id = $statusEntregado->id;
        $order->save();

        // Generar comisiones (Lógica movida desde OrderController o invocada aquí)
        $commissionService->generateForDeliveredOrder($order);

        return response()->json([
            'status' => true,
            'message' => 'Entrega aprobada exitosamente',
            'order' => $order->fresh(['status', 'deliveryReviews'])
        ]);
    }

    public function reject(Request $request, OrderDeliveryReview $review)
    {
        $user = Auth::user();
        if (!in_array($user->role?->description, ['Gerente', 'Admin'])) {
            return response()->json(['status' => false, 'message' => 'No tienes permiso para rechazar'], 403);
        }

        if ($review->status !== 'pending') {
            return response()->json(['status' => false, 'message' => 'Solicitud ya procesada'], 422);
        }

        $request->validate(['response_note' => 'nullable|string|max:1000']);

        // Actualizar review
        $review->update([
            'status' => 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'response_note' => $request->response_note,
        ]);

        // La orden se queda en "Por aprobar entrega" o regresa a "En ruta" / "Asignado a repartidor"?
        // Por seguridad, la dejamos tal cual o podríamos regresarla a un estado anterior.
        // Opción: Dejarla en 'Por aprobar entrega' pero con el review rejected, para que el repartidor intente de nuevo?
        // Mejor: regresar a "Asignado a repartidor" para que vuelva a intentar.
        
        $statusAsignadoRepartidor = Status::where('description', 'Asignado a repartidor')->first();
        if ($statusAsignadoRepartidor) {
             $review->order->update(['status_id' => $statusAsignadoRepartidor->id]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Entrega rechazada',
            'order' => $review->order->fresh(['status', 'deliveryReviews'])
        ]);
    }
}
