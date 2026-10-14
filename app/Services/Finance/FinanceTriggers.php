<?php

namespace App\Services\Finance;

use App\Constants\OrderStatus;
use App\Models\AgencyClosureRow;
use App\Models\AgencyTrip;
use App\Models\AgencyWeeklyClosure;
use App\Models\CompanyAccount;
use App\Models\Expense;
use App\Models\ReceivableCollection;
use App\Models\RecurringExpensePayment;
use App\Models\Earning;
use App\Models\InventoryLot;
use App\Models\InventoryTransferTrip;
use App\Models\LeaderCommission;
use App\Models\Order;
use App\Models\OrderDeliveryRoute;
use App\Models\OrderProduct;
use App\Models\OrderRefund;
use App\Models\PayablePayment;
use App\Models\Product;
use App\Models\ReconciliationItem;
use App\Models\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Lo que mantiene al día order_financials (Módulo 4 de Fran): cuando cambia algo de lo que sale la economía de una orden,
 * su fila queda marcada para que `finance:rebuild` la recalcule. Además pone orders.delivered_at (§3). Si algo de esto
 * falla, la operación sigue igual: el error queda en el log y la noche lo recalcula todo.
 */
class FinanceTriggers
{
    public static function register(): void
    {
        // §3: la fecha de entrega se pone al pasar a "Entregado" y se quita si la orden sale de ese estado
        Order::saving(function (Order $order) {
            if (!$order->isDirty('status_id')) {
                return;
            }
            self::safely(function () use ($order) {
                $delivered = self::deliveredId();
                if ($delivered && (int) $order->status_id === $delivered) {
                    $order->delivered_at = now();
                } elseif ($delivered && (int) $order->getOriginal('status_id') === $delivered) {
                    $order->delivered_at = null;
                }
            });
        });
        // Un cambio o una devolución también cambian la economía de la orden original (R3)
        Order::saved(fn (Order $order) => self::mark([$order->id, $order->parent_order_id]));

        foreach ([OrderProduct::class, AgencyTrip::class, OrderDeliveryRoute::class, Earning::class, LeaderCommission::class, OrderRefund::class] as $class) {
            $class::saved(fn (Model $m) => self::mark([$m->order_id, $m->getOriginal('order_id')]));
            $class::deleted(fn (Model $m) => self::mark([$m->order_id]));
        }

        // §19: el costo aprobado de una carrera cambia con su fila del cierre y con el estado del cierre
        AgencyClosureRow::saved(fn (AgencyClosureRow $row) => self::mark([$row->matched_order_id, $row->getOriginal('matched_order_id')]));
        AgencyWeeklyClosure::saved(function (AgencyWeeklyClosure $closure) {
            if ($closure->wasChanged('status')) {
                self::safely(fn () => OrderEconomics::markStaleWhere(DB::table('agency_closure_rows')
                    ->where('closure_id', $closure->id)->whereNotNull('matched_order_id')->select('matched_order_id')));
            }
        });

        // Entrega 2e: el costo de un lote (completado, corregido o de una compra corregida) cambia el de las órdenes que
        // tomaron unidades de ese lote
        InventoryLot::saved(function (InventoryLot $lot) {
            if ($lot->wasChanged('unit_cost_usd')) {
                self::safely(fn () => OrderEconomics::markStaleWhere(DB::table('inventory_lot_allocations as a')
                    ->join('inventory_lot_layers as l', 'l.id', '=', 'a.layer_id')->where('l.lot_id', $lot->id)
                    ->where('a.reference_type', 'Order')->whereNotNull('a.reference_id')->select('a.reference_id')));
            }
        });

        // Entrega 3 (§75, §76): un pago digital de la conciliación entra a la caja, o cambia su estado, al momento
        ReconciliationItem::saved(fn (ReconciliationItem $item) => self::safely(fn () => app(CashLedger::class)->syncReconciliationItem($item)));
        ReconciliationItem::deleted(fn (ReconciliationItem $item) => self::safely(fn () => app(CashLedger::class)->syncReconciliationItem($item->id, true)));
        // Entrega 3c: los pagos de deudas y los viajes salen de su cuenta de la caja; el cierre aprobado es una deuda (§101)
        PayablePayment::saved(fn (PayablePayment $p) => self::safely(fn () => app(CashLedger::class)->syncPayablePayment($p)));
        InventoryTransferTrip::saved(fn (InventoryTransferTrip $t) => self::safely(fn () => app(CashLedger::class)->syncTrip($t)));
        // Entrega 3d: gastos pagados (§28), pagos de recurrentes, reembolsos (§77) y cobros (§45)
        Expense::saved(fn (Expense $e) => self::safely(fn () => app(CashLedger::class)->syncExpense($e)));
        RecurringExpensePayment::saved(fn (RecurringExpensePayment $p) => self::safely(fn () => app(CashLedger::class)->syncRecurringPayment($p)));
        OrderRefund::saved(fn (OrderRefund $r) => self::safely(fn () => app(CashLedger::class)->syncRefund($r)));
        ReceivableCollection::saved(fn (ReceivableCollection $c) => self::safely(fn () => app(CashLedger::class)->syncReceivableCollection($c)));
        AgencyWeeklyClosure::saved(function (AgencyWeeklyClosure $closure) {
            if ($closure->wasChanged(['status', 'final_approved_total']) || $closure->wasRecentlyCreated) {
                self::safely(fn () => app(AgencyCash::class)->syncClosure($closure));
            }
        });
        // R14: si una cuenta del Módulo 1 pasa a otra cuenta de la caja (o cambia de método), sus pagos se mueven con ella
        CompanyAccount::saved(function (CompanyAccount $account) {
            if ($account->wasChanged(['financial_account_id', 'method', 'is_active'])) {
                self::safely(fn () => app(CashLedger::class)->syncCompanyAccount($account->id));
            }
        });

        // R5: un costo nuevo cambia el costo provisional de todas las órdenes con ese producto
        Product::saved(function (Product $product) {
            if ($product->wasChanged('cost_usd')) {
                self::safely(fn () => OrderEconomics::markStaleWhere(DB::table('order_products')
                    ->where('product_id', $product->id)->select('order_id')));
            }
        });
    }

    private static function mark(array $orderIds): void
    {
        self::safely(fn () => OrderEconomics::markStale(array_filter($orderIds)));
    }

    /** Una consulta pequeña, solo cuando cambia el estado (sin caché: en las pruebas cambian los ids). */
    private static function deliveredId(): ?int
    {
        return Status::where('description', OrderStatus::ENTREGADO)->value('id');
    }

    private static function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
