<?php

namespace App\Http\Controllers;

use App\Models\DelivererStock;
use App\Models\DelivererStockItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DelivererStockController extends Controller
{
    protected function ensureManagerOrAdmin(): void
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Admin', 'Gerente'])) {
            abort(403, 'No autorizado');
        }
    }

    /**
     * Stock actual de un repartidor (para hoy, o por fecha).
     */
    public function show(Request $request, $delivererId)
    {
        $user = Auth::user();

        // Repartidor solo puede ver su propio stock
        if ($user->role?->description === 'Repartidor' && (int)$user->id !== (int)$delivererId) {
            abort(403, 'No autorizado');
        }

        $date = $request->query('date')
            ? Carbon::parse($request->query('date'))->toDateString()
            : now()->toDateString();

        $movs = StockMovement::where('deliverer_id', $delivererId)
            ->whereDate('created_at', $date)
            ->get()
            ->groupBy('product_id');

        $items = [];

        foreach ($movs as $productId => $group) {
            $assigned = $group->where('type', 'ASSIGN')->sum('quantity');
            $returned = $group->where('type', 'RETURN')->sum('quantity');
            $sold     = $group->where('type', 'SALE')->sum('quantity');

            $qty = $assigned - $returned - $sold;

            if ($qty > 0) {
                $product = Product::find($productId);
                if ($product) {
                    $items[] = [
                        'product_id' => $product->id,
                        'name'       => $product->name,
                        'sku'        => $product->sku,
                        'quantity'   => $qty,
                    ];
                }
            }
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'date'   => $date,
                'items'  => $items,
            ]
        ]);
    }

    /**
     * Asignar stock a un repartidor (solo Admin/Gerente).
     */
    public function assign(Request $request, $delivererId)
    {
        $this->ensureManagerOrAdmin();

        $request->validate([
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|integer|exists:products,id',
            'items.*.quantity'    => 'required|integer|min:1',
        ]);

        $deliverer = User::findOrFail($delivererId);

        if ($deliverer->role?->description !== 'Repartidor') {
            return response()->json([
                'status'  => false,
                'message' => 'El usuario seleccionado no es repartidor',
            ], 400);
        }

        $createdBy = Auth::id();

        // Validar stock general suficiente
        foreach ($request->items as $item) {
            $available = $this->getWarehouseStock($item['product_id']);
            if ($available < $item['quantity']) {
                return response()->json([
                    'status'  => false,
                    'message' => "Stock insuficiente para el producto ID {$item['product_id']}",
                ], 422);
            }
        }

        // Crear movimientos ASSIGN
        foreach ($request->items as $item) {
            StockMovement::create([
                'product_id'   => $item['product_id'],
                'type'         => 'ASSIGN',
                'quantity'     => $item['quantity'],
                'deliverer_id' => $delivererId,
                'order_id'     => null,
                'created_by'   => $createdBy,
            ]);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Stock asignado al repartidor correctamente',
        ]);
    }

    /**
     * Registrar devolución de stock desde el repartidor al inventario general.
     */
    public function return(Request $request, $delivererId)
    {
        $user = Auth::user();

        // Puede hacerlo el propio repartidor o Admin/Gerente
        if (
            !in_array($user->role?->description, ['Admin', 'Gerente']) &&
            !($user->role?->description === 'Repartidor' && (int)$user->id === (int)$delivererId)
        ) {
            abort(403, 'No autorizado');
        }

        $request->validate([
            'items'               => 'required|array|min:1',
            'items.*.product_id'  => 'required|integer|exists:products,id',
            'items.*.quantity'    => 'required|integer|min:1',
        ]);

        $createdBy = Auth::id();

        foreach ($request->items as $item) {
            // Podrías validar que no devuelva más de lo que tiene, pero lo dejamos simple
            StockMovement::create([
                'product_id'   => $item['product_id'],
                'type'         => 'RETURN',
                'quantity'     => $item['quantity'],
                'deliverer_id' => $delivererId,
                'order_id'     => null,
                'created_by'   => $createdBy,
            ]);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Devolución registrada correctamente',
        ]);
    }

    /**
     * Stock general en bodega.
     */
    protected function getWarehouseStock(int $productId): int
    {
        $movs = StockMovement::where('product_id', $productId)->get();

        $in      = $movs->where('type', 'IN')->sum('quantity');
        $out     = $movs->where('type', 'OUT')->sum('quantity');
        $assign  = $movs->where('type', 'ASSIGN')->sum('quantity');
        $return  = $movs->where('type', 'RETURN')->sum('quantity');

        return $in - $out - $assign + $return;
    }
    protected function ensureRole(array $roles)
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, $roles)) abort(403, 'No autorizado');
    }

    // ============== Helpers ==============

    protected function currentDelivererStockQuery($delivererId)
    {
        // stock del repartidor = ASSIGN - RETURN - SALE por producto
        return StockMovement::select(
            'product_id',
            DB::raw("SUM(CASE WHEN type='ASSIGN' THEN quantity ELSE 0 END)
                        - SUM(CASE WHEN type='RETURN' THEN quantity ELSE 0 END)
                        - SUM(CASE WHEN type='SALE'   THEN quantity ELSE 0 END) as qty")
        )
            ->where('deliverer_id', $delivererId)
            ->groupBy('product_id');
    }

    protected function warehouseAvailableQuery()
    {
        // stock disponible en bodega: simplemente Product.stock (si descuentas al asignar)
        // Si prefieres calcular “bodega = IN-OUT-ASSIGN+RETURN”, cambia esta lógica.
        return Product::select('id as product_id', 'stock as available');
    }

    // ============== Repartidor: ver su stock ==============

    public function myStock()
    {
        $this->ensureRole(['Repartidor', 'Admin', 'Gerente']);

        $me = Auth::id();

        $my = $this->currentDelivererStockQuery($me);
        $wh = $this->warehouseAvailableQuery();

        $rows = Product::leftJoinSub($my, 'm', 'm.product_id', '=', 'products.id')
            ->leftJoinSub($wh, 'w', 'w.product_id', '=', 'products.id')
            ->select(
                'products.id',
                'products.title',
                'products.sku',
                'products.image',
                DB::raw('COALESCE(m.qty,0) as my_qty'),
                DB::raw('COALESCE(w.available,0) as warehouse_qty')
            )
            ->orderBy('products.title')
            ->get();

        return response()->json(['status' => true, 'data' => $rows]);
    }

    // ============== Gerente/Admin: ver stock de un repartidor específico ==============

    public function byDeliverer($delivererId)
    {
        $this->ensureRole(['Admin', 'Gerente']);

        $my = $this->currentDelivererStockQuery($delivererId);
        $wh = $this->warehouseAvailableQuery();

        $rows = Product::leftJoinSub($my, 'm', 'm.product_id', '=', 'products.id')
            ->leftJoinSub($wh, 'w', 'w.product_id', '=', 'products.id')
            ->select(
                'products.id',
                'products.title',
                'products.sku',
                'products.image',
                DB::raw('COALESCE(m.qty,0) as my_qty'),
                DB::raw('COALESCE(w.available,0) as warehouse_qty')
            )
            ->orderBy('products.title')
            ->get();

        return response()->json(['status' => true, 'data' => $rows]);
    }


    // ============== Repartidor: ver movimientos ==============

    public function myMovements()
    {
        $this->ensureRole(['Repartidor', 'Admin', 'Gerente']);

        $delivererId = Auth::id();

        $rows = StockMovement::with('product:id,title,sku')
            ->where('deliverer_id', $delivererId)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['status' => true, 'data' => $rows]);
    }
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



    protected function authorizeManager()
    {
        $role = Auth::user()->role?->description;
        if (!in_array($role, ['Gerente', 'Admin'])) abort(403, 'No autorizado');
    }
}
