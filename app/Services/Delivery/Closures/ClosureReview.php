<?php

namespace App\Services\Delivery\Closures;

use App\Models\AgencyClosureDiscrepancy;
use App\Models\AgencyClosureRow;
use App\Models\AgencyClosureSetting;
use App\Models\AgencyTrip;
use App\Models\AgencyWeeklyClosure;
use App\Models\DeliveryAuditLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * La revisión de un cierre (Módulo 3 de Fran, §29 a §37):
 *  - cada fila emparejada se compara con lo calculado, con la tolerancia de la agencia (§29, §30); si cuadra, no pide
 *    atención (§32) y se aprueba lo que cobra la agencia;
 *  - lo que no cuadra es una discrepancia: importe diferente, sin emparejar, ambigua, cobrada dos veces o sin cálculo
 *    interno (§32, §42);
 *  - el Admin resuelve cada una (§33, §34). Mientras haya alguna abierta, el cierre está "Pendiente de revisión"; cuando
 *    no queda ninguna, el monto final aprobado es la suma de lo aprobado y el cierre queda "Listo para pago" (§35, caso 14);
 *  - el pago se registra con sus datos (§37). Todo queda en la auditoría (§40).
 */
class ClosureReview
{
    /** Compara todas las filas del cierre y crea sus discrepancias (después de procesar el archivo). */
    public function evaluate(AgencyWeeklyClosure $closure): void
    {
        $setting = AgencyClosureSetting::for($closure->agency_id);
        $rows = $closure->rows()->get();
        $usedElsewhere = $this->tripsInOtherClosures($closure, $rows->pluck('matched_trip_id')->filter()->all());
        $seen = [];
        foreach ($rows as $row) {
            $this->evaluateRow($row, $setting, $seen, $usedElsewhere);
        }
        $this->refreshTotals($closure);
    }

    /**
     * Una fila: si cuadra, queda aprobada con lo que cobra la agencia; si no, su discrepancia.
     * @param array<int, int> $seen carreras ya tomadas por otra fila de este cierre
     * @param array<int, string> $usedElsewhere carreras cobradas en otro cierre → su semana
     */
    private function evaluateRow(AgencyClosureRow $row, AgencyClosureSetting $setting, array &$seen, array $usedElsewhere): void
    {
        $kind = null;
        $reason = null;
        if ($row->match_status === AgencyClosureRow::UNMATCHED) {
            $kind = 'unmatched';
        } elseif ($row->match_status === AgencyClosureRow::POSSIBLE) {
            $kind = 'ambiguous';
        } elseif (isset($seen[$row->matched_trip_id])) {
            $kind = 'duplicate';
            $reason = 'Otra fila del archivo ya cobra esta carrera.';
        } elseif (isset($usedElsewhere[$row->matched_trip_id])) {
            $kind = 'duplicate';
            $reason = "Esta carrera ya se cobró en el cierre de la semana {$usedElsewhere[$row->matched_trip_id]}.";
        } elseif ($row->expected_cost === null) {
            $kind = 'no_expected';
        } elseif ($row->reported_cost === null || !$setting->withinTolerance($row->expected_cost, $row->reported_cost)) {
            $kind = 'amount';
        }
        if ($row->matched_trip_id) {
            $seen[$row->matched_trip_id] ??= $row->id;
        }

        $row->update(['approved_cost' => $kind === null ? $row->reported_cost : null]);
        if ($kind === null) {
            return;
        }
        // Una carrera ya cobrada en otra fila: para el sistema, esta fila vale 0 y todo lo que cobra es la diferencia
        $expected = $kind === 'duplicate' ? 0.0 : $row->expected_cost;
        AgencyClosureDiscrepancy::create([
            'closure_id' => $row->closure_id,
            'closure_row_id' => $row->id,
            'kind' => $kind,
            'expected_cost' => $expected,
            'reported_cost' => $row->reported_cost,
            'difference' => $expected !== null && $row->reported_cost !== null ? round($row->reported_cost - $expected, 2) : null,
            'status' => 'open',
            'reason' => $reason,
        ]);
    }

    /** Las carreras que ya están en filas emparejadas de otro cierre de la agencia (un cobro repetido entre semanas). */
    private function tripsInOtherClosures(AgencyWeeklyClosure $closure, array $tripIds): array
    {
        if (!$tripIds) {
            return [];
        }

        return AgencyClosureRow::query()
            ->join('agency_weekly_closures as c', 'c.id', '=', 'agency_closure_rows.closure_id')
            ->where('c.agency_id', $closure->agency_id)->where('c.id', '!=', $closure->id)
            ->where('agency_closure_rows.is_current', true)
            ->whereIn('agency_closure_rows.match_status', [AgencyClosureRow::MATCHED, AgencyClosureRow::MANUAL])
            ->whereIn('agency_closure_rows.matched_trip_id', $tripIds)
            ->get(['agency_closure_rows.matched_trip_id', 'c.period_start', 'c.period_end'])
            ->mapWithKeys(fn ($r) => [$r->matched_trip_id => \Illuminate\Support\Carbon::parse($r->period_start)->format('d/m') . '–' . \Illuminate\Support\Carbon::parse($r->period_end)->format('d/m')])
            ->all();
    }

    /** §31 y §35: los totales, los conteos y el estado. Sin casos abiertos, el monto final y "Listo para pago". */
    public function refreshTotals(AgencyWeeklyClosure $closure, ?int $userId = null): void
    {
        if ($closure->status === AgencyWeeklyClosure::PAID) {
            return;
        }
        $rows = $closure->rows()->get();
        $discrepancies = $closure->discrepancies()->get();
        $open = $discrepancies->where('status', 'open');
        $duplicateRows = $discrepancies->where('kind', 'duplicate')->pluck('closure_row_id')->all();

        $summary = [
            'rows' => $rows->count(),
            'ok' => $rows->count() - $discrepancies->count(),
            'discrepancies' => $discrepancies->whereIn('kind', ['amount', 'duplicate', 'no_expected'])->count(),
            'unmatched' => $discrepancies->whereIn('kind', ['unmatched', 'ambiguous'])->count(),
            'open' => $open->count(),
            'open_by_kind' => $open->countBy('kind')->all(),
            'by_method' => $rows->whereIn('match_status', [AgencyClosureRow::MATCHED, AgencyClosureRow::MANUAL])->countBy('match_method')->all(),
            'trips_without_row' => $this->tripsWithoutRow($closure, $rows),
        ] + array_intersect_key($closure->summary ?? [], array_flip(['ai_note', 'warnings']));

        $ready = $rows->isNotEmpty() && $open->isEmpty();
        $before = $closure->only(['status', 'final_approved_total']);
        $closure->fill([
            'reported_total' => round($rows->sum(fn ($r) => (float) $r->reported_cost), 2),
            // El cálculo interno de las carreras emparejadas, cada carrera una vez
            'expected_total' => round($rows->reject(fn ($r) => in_array($r->id, $duplicateRows, true))->filter(fn ($r) => $r->matched_trip_id !== null && $r->match_status !== AgencyClosureRow::POSSIBLE)->sum(fn ($r) => (float) $r->expected_cost), 2),
            'summary' => $summary,
            'status' => $ready ? AgencyWeeklyClosure::READY : AgencyWeeklyClosure::PENDING_REVIEW,
            // El otro monto que eligió Fran para el cierre completo (approveAll) manda mientras el cierre siga listo
            'final_approved_total' => $ready ? ($closure->manual_total ?? round($rows->sum(fn ($r) => (float) $r->approved_cost), 2)) : null,
        ]);
        if (!$ready) {
            $closure->fill(['manual_total' => null, 'manual_reason' => null]);
        }
        if ($ready && $before['status'] !== AgencyWeeklyClosure::READY) {
            // §40: quién aprobó. Si todo cuadró solo, nadie (lo aprobó el sistema por el §32)
            $closure->fill(['approved_at' => now(), 'approved_by' => $userId]);
        } elseif (!$ready) {
            $closure->fill(['approved_at' => null, 'approved_by' => null]);
        }
        $closure->save();
        if ($before['status'] !== $closure->status && in_array(AgencyWeeklyClosure::READY, [$before['status'], $closure->status], true)) {
            DeliveryAuditLog::record($ready ? 'closure_approved' : 'closure_reopened', 'agency_weekly_closure', $closure->id, $before,
                $closure->only(['status', 'final_approved_total']), $userId);
        }
    }

    /** Las carreras de la semana que la agencia no cobró en el archivo (solo se cuentan; §31 no las pide). */
    private function tripsWithoutRow(AgencyWeeklyClosure $closure, $rows): int
    {
        $linked = $rows->pluck('matched_trip_id')->filter()->unique();

        return AgencyTrip::where('agency_id', $closure->agency_id)->whereNull('voided_at')
            ->whereBetween('trip_date', [$closure->period_start->toDateString(), $closure->period_end->toDateString()])
            ->whereNotIn('id', $linked->all() ?: [0])->count();
    }

    /**
     * §33 y §34: resolver un caso. Opciones: el cálculo del sistema, lo de la agencia, un monto a mano o incidencia (con
     * el monto que se decida, que puede ser 0). Guarda el monto, quién, cuándo, el motivo y la opción.
     */
    public function resolve(AgencyClosureDiscrepancy $d, string $option, ?float $amount, ?string $reason, int $userId): AgencyClosureDiscrepancy
    {
        $closure = $d->closure;
        $this->assertEditable($closure);
        if (!$d->is_current) {
            throw new RuntimeException('Ese caso ya no es del archivo vigente.');
        }
        $approved = match ($option) {
            'system' => $d->expected_cost ?? throw new RuntimeException('No hay cálculo del sistema para esta carrera.'),
            'agency' => $d->reported_cost ?? throw new RuntimeException('La agencia no puso monto en esta fila.'),
            'manual', 'incident' => $amount ?? throw new RuntimeException('Escribe el monto que se va a pagar (puede ser 0).'),
            default => throw new RuntimeException('Opción no válida.'),
        };
        if (in_array($option, ['manual', 'incident'], true) && trim((string) $reason) === '') {
            throw new RuntimeException('Escribe el motivo.');
        }
        if (in_array($d->kind, ['unmatched', 'ambiguous'], true) && $option === 'system') {
            throw new RuntimeException('Esta fila no tiene carrera: enlázala primero o decide el monto.');
        }

        DB::transaction(function () use ($d, $option, $approved, $reason, $userId, $closure) {
            $before = $d->only(['status', 'resolution', 'approved_cost', 'reason']);
            $d->update([
                'status' => 'resolved',
                'resolution' => $option,
                'approved_cost' => round((float) $approved, 2),
                'reason' => $reason !== null ? mb_substr(trim($reason), 0, 500) : $d->reason,
                'resolved_by' => $userId,
                'resolved_at' => now(),
            ]);
            $d->row->update(['approved_cost' => round((float) $approved, 2)]);
            DeliveryAuditLog::record('discrepancy_resolved', 'agency_closure_discrepancy', $d->id, $before,
                $d->only(['status', 'resolution', 'approved_cost', 'reason']) + ['closure_id' => $closure->id], $userId);
            $this->refreshTotals($closure->fresh(), $userId);
        });

        return $d->fresh();
    }

    /**
     * Aprobar el cierre completo con un botón (Fran, 15/10/2026: "que yo pueda aprobar el monto de la agencia y le dé a un
     * botón, o que pueda elegir otro monto y le dé a un botón"). Los casos que siguen abiertos se aprueban con lo que cobra la
     * agencia (sin monto, $0). Con otro monto ($total), ese es el monto final del cierre, con su motivo. Los casos que el
     * Admin ya resolvió uno por uno no se tocan. Deja el cierre "Listo para pago".
     */
    public function approveAll(AgencyWeeklyClosure $closure, ?float $total, ?string $reason, int $userId): AgencyWeeklyClosure
    {
        $this->assertEditable($closure);
        if ($total !== null && trim((string) $reason) === '') {
            throw new RuntimeException('Escribe el motivo del otro monto.');
        }
        if ($total !== null && $total < 0) {
            throw new RuntimeException('El monto no puede ser negativo.');
        }

        DB::transaction(function () use ($closure, $total, $reason, $userId) {
            $closure = AgencyWeeklyClosure::whereKey($closure->id)->lockForUpdate()->first();
            $this->assertEditable($closure);
            if ($closure->rows()->doesntExist()) {
                throw new RuntimeException('El archivo no tiene filas.');
            }
            $before = $closure->only(['status', 'final_approved_total', 'manual_total']);
            $why = $total === null ? 'Aprobado con el cierre completo: lo que cobra la agencia.'
                : 'Aprobado con el cierre completo, por otro monto: ' . trim((string) $reason);
            $cases = [];
            foreach ($closure->discrepancies()->where('status', 'open')->with('row')->get() as $d) {
                $amount = round((float) ($d->reported_cost ?? 0), 2);
                $d->update([
                    'status' => 'resolved',
                    'resolution' => $d->reported_cost !== null ? 'agency' : 'manual',
                    'approved_cost' => $amount,
                    'reason' => mb_substr($d->reported_cost !== null ? $why : 'La agencia no puso monto en esta fila. ' . $why, 0, 500),
                    'resolved_by' => $userId,
                    'resolved_at' => now(),
                ]);
                $d->row->update(['approved_cost' => $amount]);
                $cases[$d->id] = $amount;
            }
            $closure->fill([
                'manual_total' => $total !== null ? round($total, 2) : null,
                'manual_reason' => $total !== null ? mb_substr(trim((string) $reason), 0, 500) : null,
            ])->save();
            $this->refreshTotals($closure, $userId);
            // Quien aprueba es quien tocó el botón, aunque el cierre ya estuviera listo
            $closure->update(['approved_at' => now(), 'approved_by' => $userId]);
            // §40: un solo registro con los casos que aprobó el botón (cada caso guarda además quién, cuándo y el motivo)
            DeliveryAuditLog::record('closure_bulk_approved', 'agency_weekly_closure', $closure->id, $before, [
                'option' => $total === null ? 'agency' : 'manual',
                'final_approved_total' => $closure->final_approved_total,
                'reason' => $closure->manual_reason,
                'cases' => $cases,
            ], $userId);
        });

        return $closure->fresh();
    }

    /** Deshacer una resolución equivocada (mientras el cierre no esté pagado). */
    public function reopen(AgencyClosureDiscrepancy $d, int $userId): void
    {
        $this->assertEditable($d->closure);
        DB::transaction(function () use ($d, $userId) {
            $before = $d->only(['status', 'resolution', 'approved_cost', 'reason']);
            $d->update(['status' => 'open', 'resolution' => null, 'approved_cost' => null, 'resolved_by' => null, 'resolved_at' => null]);
            $d->row->update(['approved_cost' => null]);
            DeliveryAuditLog::record('discrepancy_reopened', 'agency_closure_discrepancy', $d->id, $before, $d->only(['status']), $userId);
            $this->refreshTotals($d->closure->fresh(), $userId);
        });
    }

    /** §33 "añadir nota": se agregan, no se borran. */
    public function addNote(AgencyClosureDiscrepancy $d, string $text, int $userId, string $userName): void
    {
        $notes = $d->notes ?? [];
        $notes[] = ['user_id' => $userId, 'user' => $userName, 'at' => now()->toDateTimeString(), 'text' => mb_substr(trim($text), 0, 1000)];
        $d->update(['notes' => $notes]);
        DeliveryAuditLog::record('discrepancy_note', 'agency_closure_discrepancy', $d->id, null, ['text' => end($notes)['text']], $userId);
    }

    /**
     * §33 "enlazar manualmente una fila": la fila pasa a esa carrera (manually_matched, método manual, §28). Su caso
     * anterior queda en el historial y la fila se vuelve a comparar.
     */
    public function link(AgencyClosureRow $row, AgencyTrip $trip, int $userId, ClosureMatcher $matcher): void
    {
        $closure = $row->closure;
        $this->assertEditable($closure);
        if (!$row->is_current) {
            throw new RuntimeException('Esa fila ya no es del archivo vigente.');
        }
        if ((int) $trip->agency_id !== (int) $closure->agency_id || $trip->voided_at) {
            throw new RuntimeException('Esa carrera no es de esta agencia o está anulada.');
        }
        DB::transaction(function () use ($row, $trip, $userId, $matcher, $closure) {
            $before = $row->only(['match_status', 'match_method', 'matched_trip_id', 'matched_order_id']);
            $matcher->link($row, $trip, 'manual', AgencyClosureRow::MANUAL);
            $row->save();
            AgencyClosureDiscrepancy::where('closure_row_id', $row->id)->where('is_current', true)
                ->update(['is_current' => false, 'updated_at' => now()]);
            $seen = $closure->rows()->where('id', '!=', $row->id)->whereNotNull('matched_trip_id')
                ->whereIn('match_status', [AgencyClosureRow::MATCHED, AgencyClosureRow::MANUAL])->pluck('id', 'matched_trip_id')->all();
            $this->evaluateRow($row, AgencyClosureSetting::for($closure->agency_id), $seen, $this->tripsInOtherClosures($closure, [$trip->id]));
            DeliveryAuditLog::record('row_linked', 'agency_closure_row', $row->id, $before,
                $row->only(['match_status', 'match_method', 'matched_trip_id', 'matched_order_id']) + ['closure_id' => $closure->id], $userId);
            $this->refreshTotals($closure->fresh(), $userId);
        });
    }

    /** §37: el pago. Solo de un cierre listo para pago (caso 13: con casos abiertos no se puede). */
    public function pay(AgencyWeeklyClosure $closure, array $data, int $userId): void
    {
        if ($closure->status !== AgencyWeeklyClosure::READY) {
            throw new RuntimeException($closure->status === AgencyWeeklyClosure::PAID
                ? 'Este cierre ya está pagado.'
                : 'El cierre todavía no está listo para pago: quedan casos por resolver.');
        }
        $before = $closure->only(['status', 'paid_at', 'payment_amount']);
        $closure->update([
            'status' => AgencyWeeklyClosure::PAID,
            'paid_at' => now(),
            'paid_by' => $userId,
            'payment_amount' => round((float) ($data['amount'] ?? $closure->final_approved_total), 2),
            'payment_date' => $data['date'],
            'payment_method' => $data['method'] ?? null,
            'payment_reference' => $data['reference'] ?? null,
            'payment_receipt_path' => $data['receipt_path'] ?? null,
        ]);
        DeliveryAuditLog::record('closure_paid', 'agency_weekly_closure', $closure->id, $before,
            $closure->only(['status', 'paid_at', 'payment_amount', 'payment_date', 'payment_method', 'payment_reference']), $userId);
    }

    public function assertEditable(AgencyWeeklyClosure $closure): void
    {
        if ($closure->status === AgencyWeeklyClosure::PAID) {
            throw new RuntimeException('El cierre ya está pagado: no se puede cambiar.');
        }
        if (in_array($closure->status, [AgencyWeeklyClosure::PENDING_FILE, AgencyWeeklyClosure::FILE_RECEIVED, AgencyWeeklyClosure::PROCESSING], true)) {
            throw new RuntimeException('El archivo todavía no está procesado.');
        }
    }
}
