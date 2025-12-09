<?php

// app/Http/Controllers/RosterController.php
namespace App\Http\Controllers;

use App\Models\DailyAgentRoster;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RosterController extends Controller
{
    protected function ensureManager(): void
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Gerente', 'Admin'])) abort(403, 'No autorizado');
    }

    public function today()
    {
        $this->ensureManager();

        $today = now()->toDateString();
        $rows = DailyAgentRoster::with('agent:id,names,surnames,email')
            ->where('date', $today)
            ->where('is_active', true)
            ->get();

        // lista de todos los vendedores (para seleccionar)
        $roleId = Role::where('description', 'Vendedor')->value('id');
        $agents = User::where('role_id', $roleId)->select('id', 'names', 'surnames', 'email')->orderBy('names')->get();

        return response()->json([
            'status' => true,
            'data'   => [
                'active' => $rows->pluck('agent'),
                'all'    => $agents,
            ]
        ]);
    }

    public function setToday(Request $request)
    {
        $this->ensureManager();

        $request->validate([
            'agent_ids' => 'required|array|min:1',
            'agent_ids.*' => 'integer|exists:users,id'
        ]);

        $today = now()->toDateString();

        // limpiamos roster existente de hoy
        DailyAgentRoster::where('date', $today)->delete();

        // insertamos nuevos
        $payload = collect($request->agent_ids)->unique()->map(fn($id) => [
            'date' => $today,
            'agent_id' => $id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->values()->all();

        DB::table('daily_agent_rosters')->insert($payload);

        return response()->json([
            'status' => true,
            'message' => 'Roster actualizado',
            'data'   => $payload,
        ]);
    }
}
