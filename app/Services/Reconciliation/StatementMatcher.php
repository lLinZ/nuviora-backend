<?php

namespace App\Services\Reconciliation;

use App\Models\BankStatementRow;
use App\Models\CompanyAccount;
use App\Models\ReconciliationDay;
use App\Models\ReconciliationItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Busca cada pago digital de un día en los extractos subidos para ese día (documento de Fran del 2026-10-06):
 * - §18: el monto real del comprobante, al céntimo, más la referencia y la cuenta. Aquí no se aplica el 5 %.
 * - §19: la referencia se compara solo con sus dígitos y sin ceros a la izquierda. Además, cada banco la escribe con su
 *   formato (Banesco muestra 11 dígitos; en Provincial el comprobante trae 9 con ceros adelante; otros, 12): con el
 *   mismo monto, que coincidan los últimos 6 dígitos es la misma referencia ("diferencias de formato"). Lo decidió el
 *   usuario el 2026-10-07 con el extracto real del 5 y el 6 de octubre (infra/conciliacion/informe-tarea0.md). La IA
 *   nunca decide que dos referencias son iguales: son reglas fijas.
 * - §20: Conciliado, Pago no encontrado o Requiere revisión (hay una posible coincidencia sin seguridad suficiente).
 *   Lo que resolvió el administrador no se toca.
 * - §21: cada pago se concilia por separado y un movimiento del extracto sirve para un solo pago.
 * - §23: solo se busca lo que el negocio registró; los demás movimientos del extracto no se miran.
 * Son reglas fijas, sin IA: con los mismos datos, siempre el mismo resultado.
 */
class StatementMatcher
{
    /** Cuántos días antes de la fecha del comprobante y después del día se mira el extracto. */
    private const DAYS_BEFORE = 1;
    private const DAYS_AFTER = 2;

    /** Cuántos dígitos del final de la referencia tienen que coincidir cuando el banco la escribe con otro formato. */
    private const TAIL = 6;

    private const MAX_CANDIDATES = 5;

    public function matchDay(ReconciliationDay $day): void
    {
        $statements = $day->statements()->current()->with('source:id,name')->get();
        if ($statements->isEmpty()) {
            return;
        }
        $sourceOf = $statements->mapWithKeys(fn ($s) => [$s->id => (int) $s->statement_source_id]);
        $sourceName = $statements->pluck('source.name', 'statement_source_id');
        $rows = BankStatementRow::whereIn('bank_statement_id', $statements->pluck('id'))->orderBy('id')->get()
            ->each(fn ($r) => $r->source_id = $sourceOf[$r->bank_statement_id]);
        $methodSources = CompanyAccount::where('is_active', true)->whereNotNull('statement_source_id')->get()
            ->groupBy('method')->map(fn ($g) => $g->pluck('statement_source_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $items = $day->items()->orderBy('id')->get();
        // Lo que decidió el administrador se queda como está, con su movimiento (§20). Movimiento usado => orden
        $used = $items->whereIn('status', ReconciliationItem::MANUAL)->whereNotNull('bank_statement_row_id')
            ->mapWithKeys(fn ($i) => [$i->bank_statement_row_id => $i->order_name ?? "#{$i->order_id}"])->all();
        $todo = $items->whereNotIn('status', ReconciliationItem::MANUAL)->filter(function (ReconciliationItem $item) use ($methodSources, $sourceOf) {
            // Solo los pagos cuyo extracto ya se subió
            return array_intersect($this->sources($item, $methodSources), $sourceOf->all()) !== [];
        });

        // 1. Misma referencia y mismo monto, en la cuenta del pago
        $results = [];
        foreach ($todo as $item) {
            if ($item->amount === null || $item->reference_norm === null) {
                continue;
            }
            $exact = $rows->filter(fn ($r) => !isset($used[$r->id]) && $this->inSources($r, $item, $methodSources)
                && $r->reference_norm === $item->reference_norm && $this->sameAmount($r->amount, $item->amount) && $this->inWindow($r, $item, $day));
            if ($exact->count() === 1 && $item->statement_source_id) {
                $row = $exact->first();
                $used[$row->id] = $item->order_name;
                $results[$item->id] = [ReconciliationItem::MATCHED, $row->id, "Misma referencia y mismo monto en la fila {$row->line} del extracto.", null];
            } elseif ($exact->isNotEmpty()) {
                $note = $exact->count() > 1
                    ? "Hay {$exact->count()} movimientos con la misma referencia y el mismo monto."
                    : 'Está en el extracto de ' . ($sourceName[$exact->first()->source_id] ?? '?') . ', pero no se sabe a qué cuenta fue el pago.';
                $results[$item->id] = [ReconciliationItem::REVIEW, null, $note, $exact->take(self::MAX_CANDIDATES)->map(fn ($r) => $this->candidate($r, 'Misma referencia y mismo monto'))->values()->all()];
            }
        }

        // 2. Mismo monto y la referencia termina igual (los últimos 6 dígitos): el formato de cada banco (§19)
        foreach ($todo as $item) {
            if (isset($results[$item->id]) || $item->amount === null || $this->tail($item->reference) === null) {
                continue;
            }
            $tail = $rows->filter(fn ($r) => !isset($used[$r->id]) && $this->inSources($r, $item, $methodSources)
                && $this->tail($r->reference) === $this->tail($item->reference) && $this->sameAmount($r->amount, $item->amount) && $this->inWindow($r, $item, $day));
            if ($tail->count() === 1 && $item->statement_source_id) {
                $row = $tail->first();
                $used[$row->id] = $item->order_name;
                $results[$item->id] = [ReconciliationItem::MATCHED, $row->id, "Mismo monto y la referencia termina igual (el banco la escribe con otro formato) en la fila {$row->line} del extracto.", null];
            } elseif ($tail->isNotEmpty()) {
                $note = $tail->count() > 1
                    ? "Hay {$tail->count()} movimientos con el mismo monto y la referencia que termina igual."
                    : 'Está en el extracto de ' . ($sourceName[$tail->first()->source_id] ?? '?') . ', pero no se sabe a qué cuenta fue el pago.';
                $results[$item->id] = [ReconciliationItem::REVIEW, null, $note, $tail->take(self::MAX_CANDIDATES)->map(fn ($r) => $this->candidate($r, 'Mismo monto, la referencia termina igual'))->values()->all()];
            }
        }

        // 3. Lo demás: posibles coincidencias para revisar, o no encontrado
        foreach ($todo as $item) {
            if (isset($results[$item->id])) {
                continue;
            }
            $label = collect($this->sources($item, $methodSources))->map(fn ($id) => $sourceName[$id] ?? null)->filter()->implode(' y ');
            if ($item->amount === null && $item->reference_norm === null) {
                $results[$item->id] = [ReconciliationItem::NOT_FOUND, null, $item->match_note ?: 'El comprobante no tiene datos para buscarlo en el extracto.', null];
                continue;
            }
            $possible = collect();
            foreach ($rows as $r) {
                if (!$this->inSources($r, $item, $methodSources) || !$this->inWindow($r, $item, $day)) {
                    continue;
                }
                $sameRef = $item->reference_norm !== null && $r->reference_norm === $item->reference_norm;
                $sameTail = $this->tail($item->reference) !== null && $this->tail($r->reference) === $this->tail($item->reference);
                $sameAmount = $item->amount !== null && $this->sameAmount($r->amount, $item->amount);
                if ($sameRef || $sameTail) {
                    $possible->push([$r, isset($used[$r->id]) ? ($sameRef ? 'Misma referencia' : 'La referencia termina igual') . '; ese movimiento ya es de la orden ' . $used[$r->id]
                        : ($sameRef ? 'Misma referencia, otro monto' : 'La referencia termina igual, otro monto')]);
                } elseif (!isset($used[$r->id]) && $sameAmount) {
                    $possible->push([$r, $r->reference_norm === null ? 'Mismo monto, sin referencia' : 'Mismo monto, otra referencia']);
                }
            }
            if ($possible->isEmpty()) {
                $results[$item->id] = [ReconciliationItem::NOT_FOUND, null, "No está en el extracto de {$label}.", null];
                continue;
            }
            $results[$item->id] = [ReconciliationItem::REVIEW, null, $possible->first()[1] . '.', $possible->take(self::MAX_CANDIDATES)->map(fn ($p) => $this->candidate($p[0], $p[1]))->values()->all()];
        }

        foreach ($todo as $item) {
            [$status, $rowId, $note, $candidates] = $results[$item->id];
            $item->forceFill(['status' => $status, 'bank_statement_row_id' => $rowId, 'match_note' => $note, 'candidates' => $candidates]);
            if ($item->isDirty()) {
                $item->save();
            }
        }
    }

    /** Los extractos donde se busca el pago: el de su cuenta o, si no se sabe la cuenta, los de su método. */
    private function sources(ReconciliationItem $item, Collection $methodSources): array
    {
        return $item->statement_source_id ? [(int) $item->statement_source_id] : ($methodSources[$item->method] ?? []);
    }

    private function inSources(BankStatementRow $row, ReconciliationItem $item, Collection $methodSources): bool
    {
        return in_array($row->source_id, $this->sources($item, $methodSources), true);
    }

    /** Del día anterior a la fecha del comprobante hasta dos días después del día de la conciliación. */
    private function inWindow(BankStatementRow $row, ReconciliationItem $item, ReconciliationDay $day): bool
    {
        if (!$row->date) {
            return true;
        }
        $from = Carbon::parse($item->paid_on ?? $day->date)->subDays(self::DAYS_BEFORE)->startOfDay();
        $to = Carbon::parse($day->date)->addDays(self::DAYS_AFTER)->endOfDay();

        return Carbon::parse($row->date)->between($from, $to);
    }

    /** §18: el monto tiene que coincidir con el del comprobante, al céntimo. */
    private function sameAmount(float $a, float $b): bool
    {
        return (int) round($a * 100) === (int) round($b * 100);
    }

    /** Los últimos 6 dígitos de la referencia tal como se ve, con sus ceros ("000023531" → "023531"). Null si tiene menos. */
    private function tail(?string $reference): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $reference);

        return strlen($digits) >= self::TAIL ? substr($digits, -self::TAIL) : null;
    }

    private function candidate(BankStatementRow $row, string $reason): array
    {
        return [
            'row_id' => $row->id,
            'line' => $row->line,
            'date' => $row->date?->toDateString(),
            'reference' => $row->reference,
            'amount' => $row->amount,
            'description' => $row->description,
            'reason' => $reason,
        ];
    }
}
