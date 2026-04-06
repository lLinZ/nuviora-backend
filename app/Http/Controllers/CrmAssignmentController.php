<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Services\CrmAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CrmAssignmentController extends Controller
{
    /**
     * Get list of users with their CRM activity status.
     * Admin only.
     */
    public function index()
    {
        $roleIds = \App\Models\Role::whereIn('description', ['Vendedor', 'Gerente', 'Admin', 'Master'])->pluck('id');
        
        $users = User::whereIn('role_id', $roleIds)
            ->select('id', 'names', 'surnames', 'is_active_crm')
            ->orderBy('is_active_crm', 'desc')
            ->orderBy('names', 'asc')
            ->get();

        return response()->json($users);
    }

    /**
     * Toggle the CRM activity status for a user.
     * Admin only.
     */
    public function toggleStatus(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $user->update(['is_active_crm' => !$user->is_active_crm]);

        return response()->json([
            'status' => true,
            'is_active_crm' => $user->is_active_crm,
            'message' => "Estado de turno actualizado para {$user->names}"
        ]);
    }

    /**
     * Manually assign an agent to a client.
     * Admin only.
     */
    public function assign(Request $request, $clientId)
    {
        $request->validate([
            'agent_id' => 'required|exists:users,id'
        ]);

        $client = Client::findOrFail($clientId);
        $client->update(['agent_id' => $request->agent_id]);

        // Sync with the latest active order if it exists
        $latestOrder = $client->latestOrder;
        if ($latestOrder) {
            $latestOrder->load('status');
            $terminalStatuses = ['Entregado', 'Cancelado', 'Rechazado'];
            if (!$latestOrder->status || !in_array($latestOrder->status->description, $terminalStatuses)) {
                $latestOrder->update(['agent_id' => $request->agent_id]);
                
                // Optional: Log activity or broadcast
                event(new \App\Events\OrderUpdated($latestOrder));
            }
        }

        return response()->json([
            'status' => true,
            'message' => "Cliente y orden vinculada asignados exitosamente",
            'client' => $client->load(['agent', 'latestOrder.agent'])
        ]);
    }
}
