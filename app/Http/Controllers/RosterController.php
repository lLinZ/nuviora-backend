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

    public function today(Request $request)
    {
        $this->ensureManager();
        $shopId = $request->get('shop_id');

        $today = now()->toDateString();
        $query = DailyAgentRoster::with('agent:id,names,surnames,email')
            ->where('date', $today)
            ->where('is_active', true);
        
        if ($shopId) {
            $query->where('shop_id', $shopId);
        }

        $rows = $query->get();

        // lista de todos los vendedores
        // Filtramos por tienda si se especifica, para mostrar solo los vinculados a esa tienda
        $roleId = Role::where('description', 'Vendedor')->value('id');
        $agentsQuery = User::where('role_id', $roleId);
        
        if ($shopId) {
            $agentsQuery->whereHas('shops', function($q) use ($shopId) {
                $q->where('shops.id', $shopId);
            });
        }

        $agents = $agentsQuery->select('id', 'names', 'surnames', 'email')->orderBy('names')->get();

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
            'shop_id' => 'required|exists:shops,id',
            'agent_ids' => 'required|array',
            'agent_ids.*' => 'integer|exists:users,id'
        ]);

        $today = now()->toDateString();
        $shopId = $request->shop_id;

        // limpiamos roster existente de hoy para ESTA tienda
        DailyAgentRoster::where('date', $today)->where('shop_id', $shopId)->delete();

        // insertamos nuevos
        $payload = collect($request->agent_ids)->unique()->map(fn($id) => [
            'date' => $today,
            'shop_id' => $shopId,
            'agent_id' => $id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->values()->all();

        DB::table('daily_agent_rosters')->insert($payload);

        return response()->json([
            'status' => true,
            'message' => 'Roster actualizado para la tienda seleccionada',
            'data'   => $payload,
        ]);
    }
}
