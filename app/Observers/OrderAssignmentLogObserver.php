<?php

namespace App\Observers;

use App\Models\OrderAssignmentLog;
use App\Models\OrderActivityLog;

class OrderAssignmentLogObserver
{
    public function created(OrderAssignmentLog $log): void
    {
        $agentName = $log->agent->names ?? 'Desconocido';
        $assignedBy = $log->assigned_by ? ($log->assigner->names ?? 'Usuario') : 'Sistema (Auto)';
        
        OrderActivityLog::create([
            'order_id' => $log->order_id,
            'user_id' => $log->assigned_by, // Null if automated
            'action' => 'assignment_logged',
            'description' => "Asignación registrada: Agente '{$agentName}' asignado por {$assignedBy} usando estrategia '{$log->strategy}'",
            'properties' => $log->toArray()
        ]);
    }
}
