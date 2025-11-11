<?php

namespace App\Http\Controllers;

use App\Models\DelivererStock;
use App\Models\DelivererStockItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DelivererStockController extends Controller
{

    protected function role(): ?string
    {
        return Auth::user()->role?->description;
    }

    protected function ensureDeliverer(): void
    {
        if ($this->role() !== 'Repartidor' && $this->role() !== 'Repartidor') {
            // por si el nombre es "Repartidor" exacto. Ajusta si usas "Deliverer".
        }
    }

    protected function ensureManager(): void
    {
        if (!in_array($this->role(), ['Gerente', 'Admin'])) abort(403, 'No autorizado');
    }



    public function open(Request $request)
    {
        // Repartidor abre SU jornada con items
        if ($this->role() !== 'Repartidor') abort(403, 'Solo repartidores pueden abrir su jornada');

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
        ]);

        $userId = Auth::id();
        $today = now()->toDateString();

        // evita duplicado
        if (DelivererStock::where('deliverer_id', $userId)->where('date', $today)->exists()) {
            return response()->json(['status' => false, 'message' => 'Ya tienes una jornada abierta hoy'], 422);
        }

        $stock = DB::transaction(function () use ($request, $userId, $today) {
            $header = DelivererStock::create([
                'date' => $today,
                'deliverer_id' => $userId,
                'status' => 'open',
            ]);

            foreach ($request->items as $it) {
                $p = Product::lockForUpdate()->find($it['product_id']);
                if (!$p) abort(422, 'Producto no encontrado');
                if ($p->stock < $it['qty']) abort(422, "Stock insuficiente para {$p->title}");

                // descuenta del inventario general
                $p->decrement('stock', $it['qty']);

                DelivererStockItem::create([
                    'deliverer_stock_id' => $header->id,
                    'product_id' => $p->id,
                    'qty_assigned' => $it['qty'],
                    'qty_delivered' => 0,
                    'qty_returned' => 0,
                ]);
            }

            return $header->load('items.product:id,title,sku,price,cost');
        });

        return response()->json(['status' => true, 'message' => 'Jornada abierta', 'data' => $stock]);
    }

    public function addItems(Request $request)
    {
        if ($this->role() !== 'Repartidor') abort(403, 'Solo repartidores');

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
        ]);

        $userId = Auth::id();
        $today = now()->toDateString();
        $stock = DelivererStock::where('deliverer_id', $userId)->where('date', $today)->where('status', 'open')->first();
        if (!$stock) return response()->json(['status' => false, 'message' => 'No tienes jornada abierta'], 422);

        DB::transaction(function () use ($request, $stock) {
            foreach ($request->items as $it) {
                $p = Product::lockForUpdate()->find($it['product_id']);
                if ($p->stock < $it['qty']) abort(422, "Stock insuficiente para {$p->title}");
                $p->decrement('stock', $it['qty']);

                $row = DelivererStockItem::firstOrCreate(
                    ['deliverer_stock_id' => $stock->id, 'product_id' => $p->id],
                    ['qty_assigned' => 0, 'qty_delivered' => 0, 'qty_returned' => 0]
                );
                $row->increment('qty_assigned', $it['qty']);
            }
        });

        $stock->refresh()->load('items.product:id,title,sku,price,cost');

        return response()->json(['status' => true, 'message' => 'Stock agregado', 'data' => $stock]);
    }

    public function registerDeliver(Request $request)
    {
        if ($this->role() !== 'Repartidor') abort(403, 'Solo repartidores');
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'qty' => 'required|integer|min:1',
        ]);

        $userId = Auth::id();
        $today = now()->toDateString();

        $stock = DelivererStock::where('deliverer_id', $userId)->where('date', $today)->where('status', 'open')->first();
        if (!$stock) return response()->json(['status' => false, 'message' => 'No tienes jornada abierta'], 422);

        $item = DelivererStockItem::where('deliverer_stock_id', $stock->id)->where('product_id', $request->product_id)->first();
        if (!$item) return response()->json(['status' => false, 'message' => 'Producto no está en tu stock'], 422);

        $onHand = $item->qty_assigned - $item->qty_delivered - $item->qty_returned;
        if ($request->qty > $onHand) return response()->json(['status' => false, 'message' => 'Cantidad supera disponible'], 422);

        $item->increment('qty_delivered', $request->qty);
        $item->refresh();

        return response()->json(['status' => true, 'message' => 'Entrega registrada', 'data' => $item]);
    }

    public function close(Request $request)
    {
        if ($this->role() !== 'Repartidor') abort(403, 'Solo repartidores');

        $request->validate([
            'returns' => 'required|array|min:0',
            'returns.*.product_id' => 'required|integer|exists:products,id',
            'returns.*.qty' => 'required|integer|min:0',
        ]);

        $userId = Auth::id();
        $today = now()->toDateString();

        $stock = DelivererStock::with('items')->where('deliverer_id', $userId)->where('date', $today)->where('status', 'open')->first();
        if (!$stock) return response()->json(['status' => false, 'message' => 'No tienes jornada abierta'], 422);

        DB::transaction(function () use ($request, $stock) {
            // aplicar devoluciones
            foreach ($request->returns as $rtn) {
                $item = $stock->items->firstWhere('product_id', $rtn['product_id']);
                if (!$item) continue;
                $onHand = $item->qty_assigned - $item->qty_delivered - $item->qty_returned;
                $qty = min($rtn['qty'], max(0, $onHand)); // no permitir devolver más de lo disponible

                if ($qty > 0) {
                    $item->increment('qty_returned', $qty);
                    // devolver al inventario general
                    Product::where('id', $item->product_id)->lockForUpdate()->increment('stock', $qty);
                }
            }

            $stock->update(['status' => 'closed']);
        });

        $stock->refresh()->load('items.product:id,title,sku,price,cost');

        return response()->json(['status' => true, 'message' => 'Jornada cerrada', 'data' => $stock]);
    }

    // Gerente/Admin: ver filtros
    public function index(Request $request)
    {
        $this->ensureManager();
        $date = $request->query('date', now()->toDateString());
        $delivererId = $request->query('deliverer_id');

        $q = DelivererStock::with(['deliverer:id,names,surnames,email', 'items.product:id,title,sku,price,cost'])
            ->where('date', $date);

        if ($delivererId) $q->where('deliverer_id', $delivererId);

        return response()->json(['status' => true, 'data' => $q->orderBy('deliverer_id')->get()]);
    }
    public function mineToday()
    {
        if (!in_array($this->role(), ['Repartidor', 'Gerente', 'Admin'])) abort(403, 'No autorizado');

        $userId = Auth::id();
        $today = now()->toDateString();

        $stock = DelivererStock::with(['items.product:id,title,sku,price,cost'])
            ->where('deliverer_id', $userId)
            ->where('date', $today)
            ->first();

        return response()->json([
            'status' => true,
            'data' => $stock,
        ]);
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
