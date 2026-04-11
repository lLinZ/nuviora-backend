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

        // Get all agents currently "On Duty" for CRM
        $activeAgents = User::where('is_active_crm', true)
            ->whereHas('role', function($q) {
                $q->whereIn('description', ['Vendedor', 'Gerente', 'Admin', 'Master']);
            })
            ->orderBy('id', 'asc')
            ->get();

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
