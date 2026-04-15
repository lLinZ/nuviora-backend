<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CrmAssignmentService
{
    /**
     * Assigns the next available agent in the rotation to a client.
     * Uses a round-robin approach stored in Cache.
     */
    public static function assignNextAgent($client)
    {
        // If already assigned, do nothing
        if ($client->agent_id) {
            return User::find($client->agent_id);
        }

        // Obtener vendedores que estén activos HOY en cualquier tienda, basándonos en el Roster Oficial
        $activeRosters = \App\Models\DailyAgentRoster::with('agent.role')
            ->where('date', now()->toDateString())
            ->where('is_active', true)
            ->get();
            
        // Extraer los agentes únicos con su rol permitido
        $activeAgents = $activeRosters->pluck('agent')->filter(function ($agent) {
            if (!$agent || !$agent->role) return false;
            return in_array($agent->role->description, ['Vendedor', 'Gerente', 'Admin', 'Master']);
        })->unique('id')->values();

        if ($activeAgents->isEmpty()) {
            Log::warning("CrmAssignmentService: No active agents found for assignment. Lead #{$client->id} remains unassigned.");
            return null;
        }

        // Round Robin index management
        $lastIndex = Cache::get('crm_assignment_last_index', -1);
        $nextIndex = ($lastIndex + 1) % $activeAgents->count();
        
        $agent = $activeAgents[$nextIndex];
        
        $client->update(['agent_id' => $agent->id]);

        // Sync active conversation if it exists
        \App\Models\WhatsappConversation::where('client_id', $client->id)
            ->where('status', 'open')
            ->update(['agent_id' => $agent->id]);
        
        Cache::forever('crm_assignment_last_index', $nextIndex);
        
        Log::info("CrmAssignmentService: Assigned Lead #{$client->id} ({$client->phone}) to Agent #{$agent->id} ({$agent->names}) [Index: {$nextIndex}]");
        
        return $agent;
    }
}
