<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransfer;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryTransfers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Traslados con tránsito (Módulo 5 de Fran, §15, §92, R2). Crear uno es como el traslado de siempre (el Admin, o la agencia
 * desde su almacén); recibirlo, cancelarlo o hacerlo salir lo hace un supervisor (R2: "lo marcas tú").
 */
class InventoryTransferController extends Controller
{
    public function __construct(private InventoryTransfers $transfers)
    {
    }

    /** Los traslados, por estado: en tránsito por defecto. La agencia ve solo los suyos. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...array_keys(InventoryTransfer::STATUSES), 'open', 'all'])],
            'warehouse_id' => 'nullable|integer',
        ]);
        $status = $data['status'] ?? InventoryTransfer::IN_TRANSIT;
        $user = Auth::user();
        $transfers = InventoryTransfer::with(['lines.product:id,title,showable_name', 'lines.variant:id,title', 'fromWarehouse:id,name', 'toWarehouse:id,name', 'creator:id,names', 'purchase:id,status'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', [...InventoryTransfer::NOT_DISPATCHED, InventoryTransfer::IN_TRANSIT]))
            ->when(!in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($data['warehouse_id'] ?? null, fn ($q, $w) => $q->where(fn ($s) => $s->where('from_warehouse_id', $w)->orWhere('to_warehouse_id', $w)))
            ->when($user?->role?->description === 'Agencia', function ($q) use ($user) {
                $own = Warehouse::where('user_id', $user->id)->pluck('id');
                $q->where(fn ($s) => $s->whereIn('from_warehouse_id', $own)->orWhereIn('to_warehouse_id', $own));
            })
            ->orderByDesc('id')->limit(300)->get();

        return response()->json([
            'transfers' => $transfers->map->toPayload()->values(),
            'statuses' => InventoryTransfer::STATUSES,
        ]);
    }

    /** Un traslado nuevo, con varias líneas. Sale en el momento: queda "En tránsito" (R2). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_warehouse_id' => 'required|integer|exists:warehouses,id',
            'to_warehouse_id' => 'required|integer|exists:warehouses,id|different:from_warehouse_id',
            'notes' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.variant_id' => 'nullable|integer',
            'lines.*.quantity' => 'required|integer|min:1',
        ], [
            'to_warehouse_id.different' => 'El origen y el destino tienen que ser distintos.',
            'lines.required' => 'Agrega al menos un producto.',
        ]);
        if (($denied = $this->ownsWarehouse((int) $data['from_warehouse_id'])) !== true) {
            return response()->json(['success' => false, 'message' => $denied], 403);
        }
        foreach ($data['lines'] as $i => $line) {
            if (!empty($line['variant_id']) && !ProductVariant::whereKey($line['variant_id'])->where('product_id', $line['product_id'])->exists()) {
                throw ValidationException::withMessages(["lines.{$i}.variant_id" => 'Esa talla no es de ese producto.']);
            }
        }

        try {
            $transfer = $this->transfers->create((int) $data['from_warehouse_id'], (int) $data['to_warehouse_id'], $data['lines'], $data['notes'] ?? null);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json([
            'success' => true,
            'message' => "Traslado en camino: {$transfer->units()} unidades. Entran a {$transfer->toWarehouse?->name} cuando lo marques \"Recibido\".",
            'transfer' => $transfer->toPayload(),
        ], 201);
    }

    public function send(InventoryTransfer $transfer): JsonResponse
    {
        return $this->step(fn () => $this->transfers->dispatch($transfer), 'El traslado salió: va en tránsito.');
    }

    public function receive(InventoryTransfer $transfer): JsonResponse
    {
        return $this->step(fn () => $this->transfers->receive($transfer), 'Traslado recibido: las unidades ya están en el destino.');
    }

    public function cancel(Request $request, InventoryTransfer $transfer): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:500'], ['reason.required' => 'Escribe por qué se cancela.']);

        return $this->step(fn () => $this->transfers->cancel($transfer, $data['reason']), 'Traslado cancelado.');
    }

    private function step(callable $fn, string $message): JsonResponse
    {
        try {
            $transfer = $fn();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json(['success' => true, 'message' => $message, 'transfer' => $transfer->toPayload()]);
    }

    /** Como en el traslado de siempre: un supervisor desde cualquier almacén, la agencia solo desde el suyo. */
    private function ownsWarehouse(int $warehouseId): bool|string
    {
        $role = Auth::user()?->role?->description;
        if (in_array($role, ['Admin', 'Gerente', 'Master'], true)) {
            return true;
        }
        if ($role === 'Agencia' && Warehouse::whereKey($warehouseId)->where('user_id', Auth::id())->exists()) {
            return true;
        }

        return '⛔ ACCESO DENEGADO: No tienes permiso para mover stock de este almacén.';
    }
}
