<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderUpdate;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quién da el vuelto de una orden (Fran, 2026-10-03). La agencia solo puede dar vuelto del efectivo que cobró:
 * - Solo efectivo: como siempre, lo da la agencia, la empresa o las dos.
 * - Solo digital (pago móvil, transferencia, Zelle…): el pago es exacto; si el cliente se equivocó y pagó de más,
 *   lo devuelve la empresa. La agencia no da vuelto.
 * - Mixto (efectivo y digital) con vuelto de la agencia: Fran lo valida antes de que la orden siga.
 *
 * Antes, al cambiar los pagos se recalculaba el vuelto pero se quedaba guardado lo que ponía la agencia, y la
 * liquidación se lo descontaba aunque el vuelto ya fuera 0 o el pago fuera por pago móvil.
 */
class ChangeRules
{
    public const CASH_METHODS = ['DOLARES_EFECTIVO', 'BOLIVARES_EFECTIVO', 'EUROS_EFECTIVO'];

    /** Estados a los que no pasa una orden con el vuelto de la agencia sin validar. */
    public const BLOCKED_STATUSES = ['Asignar a agencia', 'Novedad Solucionada', 'Entregado'];

    private const EPSILON = 0.009;

    /** 'none', 'cash', 'digital' o 'mixed', según los pagos registrados. */
    public static function paymentKind(iterable $payments): string
    {
        $cash = $digital = false;
        foreach ($payments as $p) {
            if ((float) $p->amount <= 0) {
                continue;
            }
            in_array($p->method, self::CASH_METHODS, true) ? $cash = true : $digital = true;
        }

        return $cash && $digital ? 'mixed' : ($cash ? 'cash' : ($digital ? 'digital' : 'none'));
    }

    /**
     * Ajusta el vuelto a los pagos y a la regla de arriba. No guarda.
     * El vuelto nunca pasa de lo que el cliente pagó de más.
     */
    public static function normalize(Order $order): void
    {
        $payments = $order->payments()->get(['method', 'amount']);
        $kind = self::paymentKind($payments);

        if ($kind === 'none') {
            // Órdenes viejas sin pagos registrados: se respeta el vuelto que se escribió a mano
            $change = (float) $order->change_amount;
        } else {
            $overpaid = max(0, round((float) $payments->sum('amount') - (float) $order->current_total_price, 2));
            $change = $order->change_amount === null ? $overpaid : min((float) $order->change_amount, $overpaid);
        }

        if ($change <= self::EPSILON) {
            self::clear($order);
            return;
        }

        $order->change_amount = $change;

        if ($kind === 'digital') {
            $order->change_covered_by = 'company';
            $order->change_amount_company = $change;
            $order->change_amount_agency = 0;
            $order->change_method_agency = null;
            return;
        }

        switch ($order->change_covered_by) {
            case 'agency':
                $order->change_amount_agency = $change;
                $order->change_amount_company = 0;
                $order->change_method_company = null;
                break;
            case 'company':
                $order->change_amount_company = $change;
                $order->change_amount_agency = 0;
                $order->change_method_agency = null;
                break;
            case 'partial':
                // Si los pagos cambiaron y las partes ya no suman el vuelto, se vuelve a elegir
                if (abs((float) $order->change_amount_agency + (float) $order->change_amount_company - $change) > 0.01) {
                    $order->change_covered_by = null;
                    $order->change_amount_agency = 0;
                    $order->change_amount_company = 0;
                }
                break;
            default:
                $order->change_amount_agency = 0;
                $order->change_amount_company = 0;
        }
    }

    /** Lo que la agencia pone de vuelto según los pagos: 0 si no cobró efectivo, y nunca más de lo pagado de más. */
    public static function agencyChange(Order $order, ?Collection $payments = null): float
    {
        $payments ??= $order->relationLoaded('payments') ? $order->payments : $order->payments()->get(['method', 'amount']);
        if (!in_array(self::paymentKind($payments), ['cash', 'mixed'], true)) {
            return 0.0;
        }
        $change = (float) $order->change_amount;
        if ($change <= self::EPSILON) {
            return 0.0;
        }
        $agency = (float) $order->change_amount_agency;
        if ($order->change_covered_by === 'agency' && $agency <= 0) {
            $agency = $change; // órdenes viejas que solo guardaron el vuelto total
        }
        $overpaid = max(0, (float) $payments->sum('amount') - (float) $order->current_total_price);

        return round(min($agency, $change, $overpaid), 2);
    }

    public static function needsApproval(Order $order): bool
    {
        return self::approvalState($order)['pending'];
    }

    public static function pendingMessage(Order $order): ?string
    {
        return self::needsApproval($order)
            ? 'El cliente pagó una parte en efectivo y otra digital, y la agencia da vuelto: administración tiene que validarlo antes de que la orden siga.'
            : null;
    }

    /**
     * Si el vuelto de la agencia necesita la validación de administración (pago mixto) y si ya la tiene
     * para estos mismos pagos y montos.
     */
    public static function approvalState(Order $order): array
    {
        $payments = $order->payments()->get(['method', 'amount']);
        $kind = self::paymentKind($payments);
        $required = !$order->is_return && !$order->is_exchange && $kind === 'mixed'
            && in_array($order->change_covered_by, ['agency', 'partial'], true)
            && (float) $order->change_amount_agency > self::EPSILON;
        $extra = $required ? $order->changeExtra()->with('approver:id,names,surnames')->first() : null;
        $approved = $required && $extra?->change_approved_signature === self::signature($order, $payments);

        return [
            'payment_kind' => $kind,
            'required' => $required,
            'pending' => $required && !$approved,
            'approved' => $approved,
            'approved_by' => $approved ? trim(($extra->approver->names ?? '') . ' ' . (($extra->approver->surnames ?? '-') === '-' ? '' : $extra->approver->surnames)) : null,
            'approved_at' => $approved ? $extra->change_approved_at?->toDateTimeString() : null,
        ];
    }

    public static function approve(Order $order, User $by): void
    {
        $order->changeExtra()->updateOrCreate(['order_id' => $order->id], [
            'change_approved_by' => $by->id,
            'change_approved_at' => now(),
            'change_approved_signature' => self::signature($order),
        ]);
        OrderUpdate::create([
            'order_id' => $order->id,
            'user_id' => $by->id,
            'message' => 'Vuelto de la agencia validado ($' . number_format((float) $order->change_amount_agency, 2) . ') con pago mixto.',
        ]);
    }

    /** La agencia no da ese vuelto: se quita y la vendedora tiene que corregir los pagos o el vuelto. */
    public static function reject(Order $order, User $by, ?string $reason = null): void
    {
        $agency = (float) $order->change_amount_agency;
        $order->change_amount_agency = 0;
        $order->change_method_agency = null;
        $order->change_covered_by = (float) $order->change_amount_company > self::EPSILON ? 'company' : null;
        $order->save();

        $order->changeExtra()->update(['change_approved_by' => null, 'change_approved_at' => null, 'change_approved_signature' => null]);
        OrderUpdate::create([
            'order_id' => $order->id,
            'user_id' => $by->id,
            'message' => 'Administración no validó el vuelto de la agencia ($' . number_format($agency, 2) . ').'
                . ($reason ? " Motivo: {$reason}." : '') . ' Revisa los pagos y el vuelto.',
        ]);
    }

    /** Huella de los pagos y el vuelto validados: si cambia algo, la validación ya no vale. */
    public static function signature(Order $order, ?Collection $payments = null): string
    {
        $payments ??= $order->payments()->get(['method', 'amount']);
        $lines = $payments->map(fn ($p) => $p->method . ':' . number_format((float) $p->amount, 2, '.', ''))->sort()->values()->all();

        return hash('sha256', json_encode([
            number_format((float) $order->current_total_price, 2, '.', ''),
            number_format((float) $order->change_amount_agency, 2, '.', ''),
            $lines,
        ]));
    }

    private static function clear(Order $order): void
    {
        $order->change_amount = 0;
        $order->change_covered_by = null;
        $order->change_amount_agency = 0;
        $order->change_amount_company = 0;
        $order->change_method_agency = null;
        $order->change_method_company = null;
    }
}
