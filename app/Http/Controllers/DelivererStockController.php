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
     * Almacén del repartidor (tarea 3, punto 7): su stock vive en el sistema de almacenes, como el de
     * las agencias. Si no tiene uno y $create, se le crea al asignarle stock por primera vez.
     */
    protected function delivererWarehouse(int $delivererId, bool $create = false): ?\App\Models\Warehouse
    {
        $warehouse = \App\Models\Warehouse::where('user_id', $delivererId)->first();
        if ($warehouse || !$create) {
            return $warehouse;
        }
        $deliverer = User::find($delivererId);
        $typeId = \App\Models\WarehouseType::whereIn('code', ['repartidor', 'deliverer'])->orderByRaw("code = 'repartidor' desc")->value('id');
        if (!$deliverer || $deliverer->role?->description !== 'Repartidor' || !$typeId) {
            return null;
        }

        return \App\Models\Warehouse::create([
            'warehouse_type_id' => $typeId,
            'user_id' => $deliverer->id,
            'code' => 'rep-' . $deliverer->id,
            'name' => 'Repartidor: ' . $deliverer->names,
            'is_active' => true,
            'is_main' => false,
        ]);
    }

    /** El inventario anterior (products.stock y stock_movements) ya no se usa. */
    protected function legacyGone()
    {
        return response()->json([
            'status' => false,
            'message' => 'El stock de repartidores ahora se maneja desde su almacén (Inventario → Stock Repartidores).',
        ], 410);
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

        // Check if there's a warehouse linked to this deliverer
        $warehouse = \App\Models\Warehouse::where('user_id', $delivererId)->first();

        if ($warehouse) {
            $items = $warehouse->inventories()
                ->with('product:id,name,title,sku')
                ->get()
                ->map(function ($inv) {
                    return [
                        'product_id' => $inv->product_id,
                        'name'       => $inv->product->name ?? $inv->product->title,
                        'sku'        => $inv->product->sku,
                        'quantity'   => $inv->quantity,
                    ];
                });

            return response()->json([
                'status' => true,
                'data'   => [
                    'date'   => $date,
                    'is_warehouse' => true,
                    'warehouse_id' => $warehouse->id,
                    'items'  => $items,
                ]
            ]);
        }

        // Sin almacén todavía: no tiene stock asignado
        return response()->json([
            'status' => true,
            'data'   => [
                'date'   => $date,
                'is_warehouse' => true,
                'warehouse_id' => null,
                'items'  => [],
            ]
        ]);
    }

    /**
     * Asignar stock a un repartidor (solo Admin/Gerente).
     */
    public function assign(Request $request, $delivererId)
    {
        $this->ensureManagerOrAdmin();

        $warehouse = $this->delivererWarehouse((int) $delivererId, true);
        if ($warehouse) {
            $mainWarehouse = \App\Models\Warehouse::where('is_main', true)->first();
            if (!$mainWarehouse) {
                return response()->json(['status' => false, 'message' => 'Main warehouse not found'], 500);
            }

            $inventoryService = app(\App\Services\InventoryService::class);
            
            DB::beginTransaction();
            try {
                foreach ($request->items as $item) {
                    $inventoryService->transferBetweenWarehouses(
                        $item['product_id'],
                        $mainWarehouse->id,
                        $warehouse->id,
                        $item['quantity'],
                        Auth::id(),
                        'Asignación automática vía vista de stock'
                    );
                }
                DB::commit();

                // Recargar datos para el front
                $items = $warehouse->inventories()->with('product')->get()->map(function($inv) {
                    return [
                        'product_id' => $inv->product_id,
                        'name' => $inv->product->name ?? $inv->product->title,
                        'sku' => $inv->product->sku,
                        'quantity' => $inv->quantity
                    ];
                });

                // Inventario general (lo que queda en main)
                $inventory = \App\Models\Inventory::where('warehouse_id', $mainWarehouse->id)
                    ->with('product')
                    ->get()
                    ->map(fn($inv) => [
                        'id' => $inv->product_id,
                        'name' => $inv->product->name ?? $inv->product->title,
                        'sku' => $inv->product->sku,
                        'stock_available' => $inv->quantity
                    ]);

                return response()->json([
                    'status' => true,
                    'message' => 'Stock transferido al almacén del repartidor ✅',
                    'deliverer_stock' => $items,
                    'inventory' => $inventory
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
            }
        }

        return response()->json(['status' => false, 'message' => 'Ese usuario no es un repartidor o no se le pudo crear su almacén.'], 422);
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

        // Check if there's a warehouse linked
        $warehouse = \App\Models\Warehouse::where('user_id', $delivererId)->first();
        if ($warehouse) {
            $mainWarehouse = \App\Models\Warehouse::where('is_main', true)->first();
            if (!$mainWarehouse) {
                return response()->json(['status' => false, 'message' => 'Main warehouse not found'], 500);
            }

            $inventoryService = app(\App\Services\InventoryService::class);
            
            DB::beginTransaction();
            try {
                foreach ($request->items as $item) {
                    $inventoryService->transferBetweenWarehouses(
                        $item['product_id'],
                        $warehouse->id,
                        $mainWarehouse->id,
                        $item['quantity'],
                        Auth::id(),
                        'Devolución automática vía vista de stock'
                    );
                }
                DB::commit();

                // Recargar datos para el front
                $items = $warehouse->inventories()->with('product')->get()->map(function($inv) {
                    return [
                        'product_id' => $inv->product_id,
                        'name' => $inv->product->name ?? $inv->product->title,
                        'sku' => $inv->product->sku,
                        'quantity' => $inv->quantity
                    ];
                });

                // Inventario general (lo que hay en main ahora)
                $inventory = \App\Models\Inventory::where('warehouse_id', $mainWarehouse->id)
                    ->with('product')
                    ->get()
                    ->map(fn($inv) => [
                        'id' => $inv->product_id,
                        'name' => $inv->product->name ?? $inv->product->title,
                        'sku' => $inv->product->sku,
                        'stock_available' => $inv->quantity
                    ]);

                return response()->json([
                    'status' => true,
                    'message' => 'Stock devuelto a bodega ✅',
                    'deliverer_stock' => $items,
                    'inventory' => $inventory
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
            }
        }

        return response()->json(['status' => false, 'message' => 'Este repartidor no tiene stock asignado.'], 422);
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



    // Jornada del repartidor con products.stock (inventario anterior): reemplazada por su almacén
    public function open(Request $request)
    {
        return $this->legacyGone();
    }

    public function addItems(Request $request)
    {
        return $this->legacyGone();
    }

    public function registerDeliver(Request $request)
    {
        return $this->legacyGone();
    }

    public function close(Request $request)
    {
        return $this->legacyGone();
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
