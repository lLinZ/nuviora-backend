<?php

namespace App\Services\Finance;

use App\Constants\OrderStatus;
use App\Models\AgencyClosureRow;
use App\Models\AgencyTrip;
use App\Models\AgencyWeeklyClosure;
use App\Models\Earning;
use App\Models\LeaderCommission;
use App\Models\Order;
use App\Models\OrderDeliveryRoute;
use App\Models\OrderProduct;
use App\Models\OrderRefund;
use App\Models\Product;
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
