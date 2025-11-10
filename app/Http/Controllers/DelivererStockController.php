<?php

namespace App\Http\Controllers;

use App\Models\DelivererStock;
use App\Models\Inventory;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DelivererStockController extends Controller
{
    public function mineToday()
    {
        $today = now()->toDateString();
        $rows = DelivererStock::with('product')
            ->where('date', $today)->where('deliverer_id', Auth::id())->get();
        return response()->json(['status' => true, 'data' => $rows]);
    }

    public function assign(Request $r)
    {
        $this->authorizeManager(); // o valida rol
        $r->validate(['deliverer_id' => 'required|integer|exists:users,id', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|integer|exists:products,id', 'items.*.qty' => 'required|integer|min:1']);
        $today = now()->toDateString();

        DB::transaction(function () use ($r, $today) {
            foreach ($r->items as $it) {
                $inv = Inventory::firstOrCreate(['product_id' => $it['product_id']]);
                if ($inv->quantity < $it['qty']) abort(422, 'Stock insuficiente');
                $inv->decrement('quantity', $it['qty']);

                $row = DelivererStock::firstOrCreate(['date' => $today, 'deliverer_id' => $r->deliverer_id, 'product_id' => $it['product_id']]);
                $row->increment('qty_assigned', $it['qty']);

                StockMovement::create(['product_id' => $it['product_id'], 'type' => 'ASSIGN', 'quantity' => $it['qty'], 'deliverer_id' => $r->deliverer_id, 'created_by' => Auth::id()]);
            }
        });

        return response()->json(['status' => true, 'message' => 'Stock asignado']);
    }

    public function return(Request $r)
    {
        $r->validate(['items' => 'required|array|min:1', 'items.*.product_id' => 'required|integer|exists:products,id', 'items.*.qty' => 'required|integer|min:1']);
        $today = now()->toDateString();
        $delivererId = Auth::id();

        DB::transaction(function () use ($r, $today, $delivererId) {
            foreach ($r->items as $it) {
                $row = DelivererStock::where(['date' => $today, 'deliverer_id' => $delivererId, 'product_id' => $it['product_id']])->lockForUpdate()->first();
                if (!$row) abort(422, 'No tienes asignación para este producto hoy');
                $row->increment('qty_returned', $it['qty']);

                $inv = \App\Models\Inventory::firstOrCreate(['product_id' => $it['product_id']]);
                $inv->increment('quantity', $it['qty']);

                StockMovement::create(['product_id' => $it['product_id'], 'type' => 'RETURN', 'quantity' => $it['qty'], 'deliverer_id' => $delivererId, 'created_by' => $delivererId]);
            }
        });

        return response()->json(['status' => true, 'message' => 'Devolución registrada']);
    }

    protected function authorizeManager()
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Gerente', 'Admin'])) abort(403, 'No autorizado');
    }
}
