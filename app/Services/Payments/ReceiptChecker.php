<?php

namespace App\Services\Payments;

use App\Models\CompanyAccount;
use App\Models\Order;
use App\Models\ReceiptCheck;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compara lo que la IA leyó de cada comprobante con los pagos de la orden y con las cuentas de la empresa
 * (Fran, 2026-10-03). La IA solo lee; aquí se decide:
 * - El tipo de comprobante tiene que coincidir con el método registrado (billetes = efectivo, capture de banco
 *   en bolívares = pago móvil, etc.).
 * - Un pago digital tiene que ir a la cuenta de la empresa (teléfono, cédula, número de cuenta o correo).
 * - Los comprobantes de un método tienen que sumar lo registrado (los bolívares, con la tasa del pago), con la regla
 *   del monto del documento de Fran del 2026-10-06 (amountVerdict). La venta y lo recibido se guardan en la orden.
 * - Un pago no puede servir para dos órdenes. El duplicado lo decide el código, por capas (pedido de Fran del
 *   2026-10-09, después de un falso positivo): el mismo archivo exacto (SHA-256) o el mismo pago (referencia, monto,
 *   fecha y, en bolívares, banco) bloquean; si la referencia coincide pero algún dato no se puede comparar, o la imagen
 *   se parece mucho a otra y no hay referencia con qué comparar, es "Posible duplicado — requiere revisión" y no
 *   bloquea. La misma referencia con otro monto, otra fecha u otro banco es otro pago. Queda el motivo
 *   (duplicate_reason).
 * Si no cuadra el monto o el destino, la IA lee otra vez esos datos dígito por dígito (needsReread) y vale la
 * lectura que cuadre: leyó "3.705" donde decía 33.705. Si las dos dicen lo mismo, el pago no cuadra de verdad.
 * En modo "enforce" no se entrega la orden si algo de esto falla (deliveryBlockMessage).
 */
class ReceiptChecker
{
    public const METHOD_KIND = [
        'PAGOMOVIL' => 'pago_movil',
        'TRANSFERENCIA_BANCARIA_BOLIVARES' => 'transferencia',
        'BINANCE' => 'binance',
        'ZINLI' => 'zinli',
        'ZELLE' => 'zelle',
        'PAYPAL' => 'paypal',
        'DOLARES_EFECTIVO' => 'efectivo',
        'BOLIVARES_EFECTIVO' => 'efectivo',
        'EUROS_EFECTIVO' => 'efectivo',
    ];

    public const KIND_LABEL = [
        'pago_movil' => 'pago móvil', 'transferencia' => 'transferencia', 'binance' => 'Binance', 'zinli' => 'Zinli',
        'zelle' => 'Zelle', 'paypal' => 'PayPal', 'efectivo' => 'efectivo', 'otro' => 'otra cosa', 'ilegible' => 'ilegible',
    ];

    private const METHOD_LABEL = [
        'PAGOMOVIL' => 'pago móvil', 'TRANSFERENCIA_BANCARIA_BOLIVARES' => 'transferencia', 'BINANCE' => 'Binance',
        'ZINLI' => 'Zinli', 'ZELLE' => 'Zelle', 'PAYPAL' => 'PayPal', 'DOLARES_EFECTIVO' => 'dólares en efectivo',
        'BOLIVARES_EFECTIVO' => 'bolívares en efectivo', 'EUROS_EFECTIVO' => 'euros en efectivo',
    ];

    /** Los que se pagan en bolívares: el monto del comprobante se compara con el pago × su tasa. */
    private const VES_KINDS = ['pago_movil', 'transferencia'];

    /** Pagar de más hasta un 5 % es válido; más que eso, válido con advertencia (ver amountVerdict). */
    private const OVER_PERCENT = 5;

    private const MAX_AGE_DAYS = 3;

    /** Con dos lecturas de un dato de destino vale la mejor (que una coincida exacto con la empresa no es casualidad). */
    private const GRADE_RANK = ['different' => 0, 'unknown' => 1, 'near' => 2, 'exact' => 3];

    /**
     * La regla del monto del documento de Fran (Módulo 1, 2026-10-06):
     * - §5: en bolívares el mínimo es el monto esperado sin los decimales (33.550,71 → 33.550,00).
     * - §6: en otras monedas los decimales cuentan (32,57 USD → 32,56 no alcanza).
     * - §7 y §8: hasta un 5 % más es válido; más que eso, válido con advertencia (no se rechaza).
     * Se compara en céntimos para no depender de los decimales de un float.
     * @return 'short'|'ok'|'over'
     */
    public static function amountVerdict(float $expected, float $got, bool $ves): string
    {
        $expectedCents = (int) round($expected * 100);
        $gotCents = (int) round($got * 100);
        $minCents = $ves ? intdiv($expectedCents, 100) * 100 : $expectedCents;
        if ($gotCents < $minCents) {
            return 'short';
        }

        return $gotCents * 100 <= $expectedCents * (100 + self::OVER_PERCENT) ? 'ok' : 'over';
    }

    public static function mode(): string
    {
        $mode = config('services.receipt_checks.mode', 'off');

        return in_array($mode, ['observe', 'enforce'], true) && config('services.openai.key') ? $mode : 'off';
    }

    /** Guarda lo que leyó la IA y vuelve a revisar toda la orden. */
    public function record(ReceiptCheck $check, array $result): void
    {
        $d = $result['data'];
        $reference = preg_replace('/\D/', '', (string) ($d['referencia'] ?? ''));
        $check->fill([
            'kind' => $d['tipo'],
            'extracted' => $d,
            'reference' => strlen($reference) >= 6 ? $reference : null,
            // De una foto de billetes no se toma el total: la IA los cuenta mal (2026-10-06, ver checkCash)
            'amount' => $d['tipo'] === 'efectivo' ? null : ($d['monto'] ?? null),
            'currency' => $d['tipo'] === 'efectivo' ? ($d['efectivo_moneda'] ?? null) : ($d['moneda'] ?? null),
            'model' => $result['model'] ?? null,
            'response_id' => $result['response_id'] ?? null,
            'input_tokens' => $result['input_tokens'] ?? null,
            'output_tokens' => $result['output_tokens'] ?? null,
            'error' => null,
        ])->save();

        $this->evaluateOrder($check->order);
    }

    /**
     * Si conviene leer otra vez el comprobante, dígito por dígito: no cuadró el monto, o el destino de un pago en
     * bolívares, y todavía no se releyó. Pasar la revisión recién leída de la BD.
     */
    public function needsReread(ReceiptCheck $check): bool
    {
        if (in_array($check->kind, ['efectivo', 'otro', 'ilegible', null], true) || isset($check->extracted['relectura'])) {
            return false;
        }
        $codes = array_column($check->issues ?? [], 'code');

        return array_intersect(['amount', 'overpaid'], $codes) || (in_array('destination', $codes, true) && in_array($check->kind, self::VES_KINDS, true));
    }

    /** Guarda la segunda lectura junto a la primera y vuelve a revisar toda la orden. */
    public function recordReread(ReceiptCheck $check, array $reread): void
    {
        $extracted = $check->extracted ?? [];
        $extracted['relectura'] = Arr::only($reread, ['monto', 'monto_tal_cual', 'monto_digitos', 'telefono', 'cedula', 'cuenta']);
        $check->forceFill([
            'extracted' => $extracted,
            'input_tokens' => (int) $check->input_tokens + (int) ($reread['input_tokens'] ?? 0),
            'output_tokens' => (int) $check->output_tokens + (int) ($reread['output_tokens'] ?? 0),
        ])->save();

        $this->evaluateOrder($check->order);
    }

    /**
     * Si conviene leer el comprobante otra vez con el modelo de respaldo (Fran, 2026-10-09: "Luna → Sol → revisión
     * manual"): baja confianza, referencia o monto que no se leen, o dos lecturas del monto que se contradicen. Una sola
     * vez por comprobante. Pasar la revisión recién leída de la BD.
     */
    public function needsEscalation(ReceiptCheck $check): bool
    {
        $d = $check->extracted ?? [];
        if (!ReceiptReader::fallbackModel() || isset($d['escalado']) || in_array($check->kind, ['efectivo', 'otro', null], true)) {
            return false;
        }
        if ($check->kind === 'ilegible' || ($d['confianza'] ?? 'alta') !== 'alta') {
            return true;
        }
        foreach (['monto', 'referencia'] as $field) {
            if (($d['confianza_campos'][$field] ?? 'alta') !== 'alta') {
                return true;
            }
        }
        if (empty($d['referencia']) || ($d['monto'] ?? null) === null) {
            return true;
        }
        // Las dos lecturas del monto dicen cosas distintas y ninguna cuadra con el pago
        $reread = $d['relectura']['monto'] ?? null;

        return $reread !== null && round((float) $reread, 2) !== round((float) $d['monto'], 2)
            && in_array('amount', array_column($check->issues ?? [], 'code'), true);
    }

    /** Guarda la lectura del modelo de respaldo en lugar de la primera (que queda guardada) y revisa la orden. */
    public function recordEscalation(ReceiptCheck $check, array $result): void
    {
        $first = $check->extracted ?? [];
        $result['data']['escalado'] = [
            'modelo' => $result['model'] ?? ReceiptReader::fallbackModel(),
            'primera_lectura' => Arr::except($first, ['escalado']) + ['modelo' => $check->model],
        ];
        $result['input_tokens'] = (int) $check->input_tokens + (int) ($result['input_tokens'] ?? 0);
        $result['output_tokens'] = (int) $check->output_tokens + (int) ($result['output_tokens'] ?? 0);
        $this->record($check, $result);
    }

    /** Órdenes ya revisadas en esta pasada (para no dar vueltas al revisar las que comparten un comprobante). */
    private array $visiting = [];

    /** Revisa otra vez todos los comprobantes leídos de la orden, sin llamar a la IA (p. ej. si cambiaron los pagos). */
    public function evaluateOrder(Order $order): void
    {
        $top = $this->visiting === [];
        $this->visiting[$order->id] = true;
        try {
            $this->evaluate($order);
        } finally {
            if ($top) {
                $this->visiting = [];
            }
        }
    }

    private function evaluate(Order $order): void
    {
        $checks = ReceiptCheck::where('order_id', $order->id)->whereNotNull('kind')->with('receipt:id,created_at,file_sha256,image_dhash')->orderBy('id')->get();
        if ($checks->isEmpty()) {
            $this->saveSummary($order, []);
            return;
        }
        $others = []; // órdenes con el mismo comprobante: también se marcan
        $payments = $order->payments()->get(['method', 'amount', 'rate']);
        $accounts = $this->accounts();
        $methodsText = $payments->isEmpty() ? 'nada (no hay pagos registrados)'
            : $payments->pluck('method')->unique()->map(fn ($m) => self::METHOD_LABEL[$m] ?? $m)->implode(' y ');

        $issues = [];
        $seenRefs = [];
        $seenFiles = [];
        $duplicates = []; // por comprobante: el motivo y el otro comprobante (duplicate_reason)
        $counted = []; // por tipo, los comprobantes que suman al monto (sin repetidos)
        $accountIds = []; // por comprobante, la cuenta de la empresa a la que fue el pago
        foreach ($checks as $check) {
            $d = $check->extracted ?? [];
            $kind = $check->kind;
            $list = [];

            if ($kind === 'ilegible') {
                $list[] = $this->warn('No se lee bien el comprobante' . (!empty($d['motivo_ilegible']) ? " ({$d['motivo_ilegible']})" : '') . '. Pide al cliente una foto o un capture más claro.', 'unreadable');
            } elseif ($kind === 'otro') {
                $list[] = $this->fail('La imagen no parece un comprobante de pago ni una foto de billetes.', 'kind');
            } elseif ($kind === 'efectivo') {
                $list = array_merge($list, $this->checkCash($d, $payments, $methodsText));
            } else {
                // Pago móvil y transferencia son lo mismo para la empresa: bolívares que llegan a su banco
                $kindPayments = $this->familyPayments($payments, $kind);
                if ($kindPayments->isEmpty()) {
                    $list[] = $this->fail('El comprobante es de ' . self::KIND_LABEL[$kind] . ", pero el pago está registrado como {$methodsText}.", 'kind');
                } else {
                    [$destination, $accountIds[$check->id]] = $this->checkDestination($kind, $d, $accounts->where('kind', $kind));
                    $list = array_merge($list, $destination);
                }
                if (($d['estado'] ?? null) === 'rechazada') {
                    $list[] = $this->fail('El comprobante dice que el pago fue rechazado.', 'state');
                } elseif (($d['estado'] ?? null) === 'pendiente') {
                    $list[] = $this->warn('El comprobante dice que el pago está pendiente.', 'state');
                }
                // Contra la fecha en que se subió el comprobante (no la de la revisión, que puede ser posterior)
                if ($dateIssue = $this->checkDate($d, $check->receipt?->created_at ?? $check->created_at)) {
                    $list[] = $dateIssue;
                }

                $duplicate = false;
                $sha = $check->receipt?->file_sha256;
                $sameFile = $sha && isset($seenFiles[$sha]);
                if ($sameFile || ($check->reference && isset($seenRefs[$check->reference]))) {
                    $duplicate = true;
                    $list[] = $this->warn('Este comprobante está repetido en la orden (' . ($sameFile ? 'el mismo archivo' : 'misma referencia') . '); cuenta una sola vez.', 'duplicate');
                } else {
                    if ($sha) {
                        $seenFiles[$sha] = true;
                    }
                    if ($check->reference) {
                        $seenRefs[$check->reference] = true;
                    }
                    if ($found = $this->otherOrderDuplicate($check, $order, $kind)) {
                        $list[] = $found['issue'];
                        $duplicates[$check->id] = ['reason' => $found['reason'], 'of' => $found['others']->first()->id];
                        foreach ($found['others'] as $o) {
                            $others[$o->order_id] = true;
                        }
                    }
                }
                // Para el monto solo cuenta lo que llegó de verdad: ni repetidos ni lo que no cuadra
                if (!$duplicate && !in_array('fail', array_column($list, 'level'), true)) {
                    $counted[self::family($kind)][] = $check;
                }
            }
            $issues[$check->id] = $list;
        }

        // Monto: lo que suman los comprobantes de cada método contra lo registrado, y la venta y lo recibido de cada uno
        $summary = [];
        foreach ($counted as $family => $kindChecks) {
            [$amountIssues, $summary[$family]] = $this->checkAmount($kindChecks[0]->kind, $kindChecks, $payments);
            foreach ($amountIssues as $checkId => $issue) {
                $issues[$checkId][] = $issue;
            }
        }
        $this->saveSummary($order, array_filter($summary));

        foreach ($checks as $check) {
            $list = $issues[$check->id] ?? [];
            // Si la IA dice que no ve bien la imagen, lo del monto y el destino queda para revisar, sin bloquear
            // (2026-10-03: en una foto chiquita y de lado leyó "2.214" donde decía 22.149,00)
            if (($check->extracted['confianza'] ?? 'alta') !== 'alta') {
                $list = array_map(fn ($i) => $i['level'] === 'fail' && in_array($i['code'] ?? null, ['destination', 'amount'], true)
                    ? ['level' => 'warning', 'text' => 'Lectura dudosa (la imagen no se ve del todo bien): ' . lcfirst($i['text']), 'code' => $i['code']]
                    : $i, $list);
            }
            // Fran (2026-10-09): si tampoco el modelo de respaldo lo lee con seguridad, revisión manual (sin inventar)
            if (isset($check->extracted['escalado']) && $this->lowConfidence($check->extracted)) {
                $list[] = $this->warn('Ni con la segunda IA se lee con seguridad (' . $this->doubtfulFields($check->extracted) . '): requiere revisión manual.', 'review');
            }
            $levels = array_column($list, 'level');
            $status = $check->kind === 'ilegible' ? ReceiptCheck::UNREADABLE
                : (in_array('fail', $levels, true) ? ReceiptCheck::FAIL
                    : (in_array('warning', $levels, true) ? ReceiptCheck::WARNING : ReceiptCheck::OK));
            // También el monto, si checkAmount se quedó con el de la segunda lectura
            $check->forceFill([
                'status' => $status, 'issues' => $list, 'company_account_id' => $accountIds[$check->id] ?? null,
                'duplicate_reason' => $duplicates[$check->id]['reason'] ?? null, 'duplicate_of_check_id' => $duplicates[$check->id]['of'] ?? null,
            ]);
            if ($check->isDirty()) {
                $check->save();
            }
        }

        // La otra orden con el mismo comprobante también queda marcada
        foreach (array_keys($others) as $otherId) {
            if (empty($this->visiting[$otherId]) && ($other = Order::find($otherId))) {
                $this->visiting[$otherId] = true;
                $this->evaluate($other);
            }
        }
    }

    // --- Duplicados entre órdenes (pedido de Fran del 2026-10-09) ---

    /** Desde cuándo se buscan capturas parecidas (la huella de imagen es solo un indicio). */
    private const SIMILAR_DAYS = 30;

    /**
     * El mismo comprobante en otra orden, por capas. Devuelve el aviso, el motivo y los comprobantes de las otras
     * órdenes, o null si no hay duplicado:
     *  1. exact_file_hash: el mismo archivo exacto → bloquea.
     *  2. same_transaction_reference: la misma referencia, el mismo monto, la misma fecha y, en bolívares, el mismo
     *     banco → bloquea. possible_same_reference: la misma referencia y nada que lo contradiga, pero algún dato falta
     *     o no se lee con seguridad → "Posible duplicado — requiere revisión", no bloquea. Con un dato distinto es otro
     *     pago: no se marca.
     *  3. perceptual_similarity_only: la imagen es casi igual a la de otra orden y no hay dos referencias que comparar
     *     → posible duplicado, no bloquea.
     * @return array{issue: array, reason: string, others: Collection}|null
     */
    private function otherOrderDuplicate(ReceiptCheck $check, Order $order, string $kind): ?array
    {
        $family = $this->familyKinds($kind);
        $sha = $check->receipt?->file_sha256;
        if ($sha) {
            $same = ReceiptCheck::where('order_id', '!=', $order->id)->whereIn('kind', $family)
                ->whereHas('receipt', fn ($q) => $q->where('file_sha256', $sha))->with('order:id,name')->orderBy('id')->get();
            if ($same->isNotEmpty()) {
                return [
                    'issue' => $this->fail('Este mismo archivo de comprobante está también en la orden ' . $this->orderNames($same) . ': un pago no puede servir para dos órdenes.', 'duplicate'),
                    'reason' => 'exact_file_hash',
                    'others' => $same,
                ];
            }
        }

        if ($check->reference) {
            $sameRef = ReceiptCheck::where('reference', $check->reference)->where('order_id', '!=', $order->id)
                ->whereIn('kind', $family)->with('order:id,name')->orderBy('id')->get();
            $verdicts = $sameRef->map(fn (ReceiptCheck $o) => ['check' => $o] + $this->sameTransaction($check, $o, $kind));
            $same = $verdicts->where('verdict', 'same');
            if ($same->isNotEmpty()) {
                $others = $same->pluck('check');
                $what = in_array($kind, self::VES_KINDS, true) ? 'monto, fecha y banco' : 'monto y fecha';

                return [
                    'issue' => $this->fail("Este mismo pago (referencia {$check->reference}, mismo {$what}) está también en la orden " . $this->orderNames($others) . ': un pago no puede servir para dos órdenes.', 'duplicate'),
                    'reason' => 'same_transaction_reference',
                    'others' => $others,
                ];
            }
            $unsure = $verdicts->where('verdict', 'unsure');
            if ($unsure->isNotEmpty()) {
                return [
                    'issue' => $this->warn('Posible duplicado — requiere revisión: la orden ' . $this->orderNames($unsure->pluck('check')) . " tiene un comprobante con la misma referencia ({$check->reference}), pero no se puede comparar " . $unsure->first()['missing'] . '.', 'duplicate'),
                    'reason' => 'possible_same_reference',
                    'others' => $unsure->pluck('check'),
                ];
            }
        }

        // Sin dos referencias que comparar, una captura casi idéntica es un indicio (nunca bloquea)
        $hash = $check->receipt?->image_dhash;
        if ($hash) {
            $similar = ReceiptCheck::where('order_id', '!=', $order->id)->whereIn('kind', $family)
                ->where('created_at', '>=', now()->subDays(self::SIMILAR_DAYS))
                ->whereHas('receipt', fn ($q) => $q->whereNotNull('image_dhash'))
                ->with(['order:id,name', 'receipt:id,image_dhash'])->orderBy('id')->get()
                ->filter(fn (ReceiptCheck $o) => !($check->reference && $o->reference && $o->reference !== $check->reference)
                    && ReceiptFingerprint::distance($hash, $o->receipt->image_dhash) <= ReceiptFingerprint::NEAR_BITS);
            if ($similar->isNotEmpty()) {
                return [
                    'issue' => $this->warn('Posible duplicado — requiere revisión: la imagen es casi igual a un comprobante de la orden ' . $this->orderNames($similar) . ', y no hay referencia con qué comparar.', 'duplicate'),
                    'reason' => 'perceptual_similarity_only',
                    'others' => $similar->values(),
                ];
            }
        }

        return null;
    }

    /**
     * Si dos comprobantes con la misma referencia son el mismo pago: same (coincide todo lo que identifica al pago y se
     * lee con seguridad), different (algún dato es distinto: es otro pago) o unsure (falta algún dato o no se lee bien).
     * @return array{verdict: string, missing: ?string}
     */
    private function sameTransaction(ReceiptCheck $a, ReceiptCheck $b, string $kind): array
    {
        $da = $a->extracted ?? [];
        $db = $b->extracted ?? [];
        $sure = fn (array $d, string $field) => ($d['confianza_campos'][$field] ?? ($d['confianza'] ?? 'alta')) === 'alta';
        $results = [];
        $missing = [];

        // Monto: cualquiera de las lecturas de uno contra cualquiera del otro
        $amountsA = array_map(fn ($v) => number_format($v, 2, '.', ''), $this->amountReadings($a));
        $amountsB = array_map(fn ($v) => number_format($v, 2, '.', ''), $this->amountReadings($b));
        if (!$amountsA || !$amountsB) {
            $missing[] = 'el monto';
        } else {
            $results['monto'] = array_intersect($amountsA, $amountsB) ? 'same' : (($sure($da, 'monto') && $sure($db, 'monto')) ? 'different' : 'unsure');
        }

        // Fecha
        $dateA = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($da['fecha'] ?? '')) ? $da['fecha'] : null;
        $dateB = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($db['fecha'] ?? '')) ? $db['fecha'] : null;
        if (!$dateA || !$dateB) {
            $missing[] = 'la fecha';
        } else {
            $results['fecha'] = $dateA === $dateB ? 'same' : (($sure($da, 'fecha') && $sure($db, 'fecha')) ? 'different' : 'unsure');
        }

        // Banco, en bolívares: el de origen con el de origen y el de destino con el de destino
        if (in_array($kind, self::VES_KINDS, true)) {
            $pairs = [];
            foreach (['banco_origen', 'banco_destino'] as $f) {
                $x = $this->bankKey($da[$f] ?? null);
                $y = $this->bankKey($db[$f] ?? null);
                if ($x !== null && $y !== null) {
                    $pairs[] = $x === $y;
                }
            }
            if (!$pairs) {
                $missing[] = 'el banco';
            } else {
                $results['banco'] = !in_array(false, $pairs, true) ? 'same' : (($sure($da, 'banco') && $sure($db, 'banco')) ? 'different' : 'unsure');
            }
        }

        if (in_array('different', $results, true)) {
            return ['verdict' => 'different', 'missing' => null];
        }
        foreach ($results as $field => $r) {
            if ($r === 'unsure') {
                $missing[] = "el {$field} con seguridad";
            }
        }
        if (!$sure($da, 'referencia') || !$sure($db, 'referencia')) {
            $missing[] = 'la referencia con seguridad';
        }

        return $missing ? ['verdict' => 'unsure', 'missing' => implode(', ', array_unique($missing))] : ['verdict' => 'same', 'missing' => null];
    }

    /** El nombre del banco para comparar: sin tildes, sin "banco", "S.A." ni signos ("Banco de Venezuela" → "venezuela"). */
    private function bankKey(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $words = array_filter(preg_split('/[^a-z0-9]+/', $this->norm($name)), fn ($w) => $w !== '' && !in_array($w, ['banco', 'bank', 'de', 'del', 'universal', 's', 'a', 'c', 'sa', 'ca', 'saca', 'el', 'la'], true));

        return $words ? implode(' ', $words) : null;
    }

    private function orderNames(Collection $checks): string
    {
        return $checks->map(fn ($o) => $o->order->name ?? "#{$o->order_id}")->unique()->implode(', ');
    }

    /** Si alguna lectura importante no es segura (para la revisión manual después del respaldo). */
    private function lowConfidence(array $d): bool
    {
        return ($d['confianza'] ?? 'alta') !== 'alta'
            || ($d['confianza_campos']['monto'] ?? 'alta') !== 'alta' || ($d['confianza_campos']['referencia'] ?? 'alta') !== 'alta';
    }

    private function doubtfulFields(array $d): string
    {
        $fields = array_keys(array_filter($d['confianza_campos'] ?? [], fn ($c) => $c !== 'alta'));

        return $fields ? implode(', ', $fields) : 'la imagen no se ve bien';
    }

    /**
     * Guarda en la orden la venta, lo recibido y el excedente de cada método (§9 del documento de Fran). Va directo
     * a la tabla: no es un cambio de la orden (ni observers ni updated_at).
     */
    private function saveSummary(Order $order, array $summary): void
    {
        $query = DB::table('orders')->where('id', $order->id);
        if (!$summary) {
            $query->whereNotNull('receipts_summary'); // la mayoría no tiene comprobantes: que no escriba por nada
        }
        $query->update(['receipts_summary' => $summary ? json_encode($summary) : null]);
    }

    /** Pago móvil y transferencia son la misma familia: bolívares que llegan al banco de la empresa. */
    private static function family(?string $kind): ?string
    {
        return in_array($kind, ['pago_movil', 'transferencia'], true) ? 'bs' : $kind;
    }

    private function familyPayments(Collection $payments, string $kind): Collection
    {
        $family = self::family($kind);

        return $payments->filter(fn ($p) => self::family(self::METHOD_KIND[$p->method] ?? null) === $family);
    }

    private function familyKinds(string $kind): array
    {
        return array_values(array_filter(ReceiptReader::KINDS, fn ($k) => self::family($k) === self::family($kind)));
    }

    /**
     * Si la orden no se puede marcar entregada por los comprobantes (solo en modo "enforce"). Null si puede.
     * Lo que Fran aprobó a mano cuenta como bueno. Si la IA no pudo revisar (caída, error), no se bloquea.
     */
    public function deliveryBlockMessage(Order $order): ?string
    {
        if (self::mode() !== 'enforce' || $order->is_return || $order->is_exchange) {
            return null;
        }
        $receipts = $order->paymentReceipts()->with('check')->get();
        $checks = $receipts->pluck('check')->filter();

        $fresh = $checks->first(fn ($c) => $c->status === ReceiptCheck::PENDING && $c->created_at > now()->subMinutes(3));
        if ($fresh) {
            return 'Todavía se está revisando el comprobante. Intenta de nuevo en unos segundos.';
        }

        $bad = $checks->first(fn ($c) => in_array($c->status, [ReceiptCheck::FAIL, ReceiptCheck::UNREADABLE], true) && !$c->isApproved());
        if ($bad) {
            $reason = collect($bad->issues ?? [])->firstWhere('level', 'fail')['text'] ?? ($bad->issues[0]['text'] ?? 'El comprobante no cuadra con el pago.');

            return "No se puede entregar: {$reason} Borra ese comprobante y sube el correcto, o pide a administración que lo apruebe.";
        }

        // Cada método digital tiene que tener al menos un comprobante de ese tipo (o uno aprobado a mano, ver
        // abajo). Si hay comprobantes sin revisar (subidos antes de esta función o con error de la IA), no se exige.
        if ($receipts->contains(fn ($r) => !$r->check || in_array($r->check->status, [ReceiptCheck::PENDING, ReceiptCheck::ERROR], true))) {
            return null;
        }
        $paymentKinds = $order->payments()->pluck('method')->map(fn ($m) => self::METHOD_KIND[$m] ?? null)->filter();
        $kinds = $paymentKinds->filter(fn ($k) => $k !== 'efectivo')->unique(fn ($k) => self::family($k));
        // Lo que Fran aprobó a mano sin que se leyera como un método de la orden (ilegible, otra cosa, otro tipo)
        // cuenta como el comprobante del método que falte, uno por cada aprobado (2026-10-06: #8375 de Meloon,
        // ilegible y aprobado, seguía diciendo que faltaba el de pago móvil)
        $families = $paymentKinds->map(fn ($k) => self::family($k));
        $approvedSpare = $checks->filter(fn ($c) => $c->isApproved() && !$families->contains(self::family($c->kind)))->count();
        foreach ($kinds as $kind) {
            if ($checks->contains(fn ($c) => self::family($c->kind) === self::family($kind))) {
                continue;
            }
            if ($approvedSpare > 0) {
                $approvedSpare--;
                continue;
            }

            return 'No se puede entregar: falta el comprobante de ' . self::KIND_LABEL[$kind] . '. Ninguno de los comprobantes subidos es de ' . self::KIND_LABEL[$kind] . '.';
        }

        return null;
    }

    // --- Revisiones ---

    /**
     * Foto de billetes: solo que haya efectivo registrado y que la moneda coincida. La cantidad no se revisa
     * (2026-10-06): la IA cuenta mal los billetes de fotos tomadas de cualquier forma (dos veces el que se ve por
     * las dos puntas, $20 por $100), y lo que se le cobra a la agencia sale del pago registrado.
     */
    private function checkCash(array $d, Collection $payments, string $methodsText): array
    {
        $cash = $payments->filter(fn ($p) => (self::METHOD_KIND[$p->method] ?? null) === 'efectivo');
        if ($cash->isEmpty()) {
            return [$this->fail("La foto es de billetes, pero el pago está registrado como {$methodsText}.", 'kind')];
        }
        $list = [];
        $currencyMethod = ['USD' => 'DOLARES_EFECTIVO', 'VES' => 'BOLIVARES_EFECTIVO', 'EUR' => 'EUROS_EFECTIVO'];
        $seen = $d['efectivo_moneda'] ?? null;
        if ($seen && isset($currencyMethod[$seen]) && !$cash->contains('method', $currencyMethod[$seen])) {
            $list[] = $this->warn('Los billetes parecen ser ' . self::METHOD_LABEL[$currencyMethod[$seen]] . ', pero el efectivo está registrado como ' . $cash->pluck('method')->unique()->map(fn ($m) => self::METHOD_LABEL[$m])->implode(' y ') . '.', 'cash');
        }

        return $list;
    }

    /**
     * A qué cuenta de la empresa fue el pago. Puede haber varias cuentas del mismo método (documento de Fran del
     * 2026-10-06, §13: Mercantil, Banesco…): vale la que mejor coincide con lo que muestra el comprobante, y cada
     * pago guarda su cuenta. Si el comprobante no la deja ver y el método tiene una sola cuenta activa, es esa.
     * @return array{0: array, 1: ?int} los avisos y el id de la cuenta (null si no se sabe o no es de la empresa)
     */
    private function checkDestination(string $kind, array $d, Collection $accounts): array
    {
        $label = self::KIND_LABEL[$kind];
        if ($accounts->isEmpty()) {
            return [[$this->warn("No hay una cuenta de {$label} en Cuentas bancarias para comparar.", 'account')], null];
        }
        $best = null;
        foreach ($accounts as $account) {
            $result = $this->compareDestination($kind, $d, $account);
            // A igual coincidencia, mejor una cuenta activa
            if ($best === null || $result['rank'] > $best['rank'] || ($result['rank'] === $best['rank'] && $account['active'] && !$best['account']['active'])) {
                $best = $result + ['account' => $account];
            }
        }
        $list = $best['account']['active'] ? [] : [$this->warn("La cuenta de {$label} está desactivada en Cuentas bancarias.", 'account')];
        $active = $accounts->where('active', true);
        $accountId = match (true) {
            $best['rank'] >= 2 => $best['account']['id'],
            $best['rank'] === 1 && $active->count() === 1 => $active->first()['id'],
            default => null,
        };

        return [array_merge($list, $best['issues']), $accountId];
    }

    /**
     * Compara el destino del comprobante con una cuenta de la empresa.
     * @return array{rank: int, issues: array} rank: 3 coincide, 2 parecido, 1 no se ve, 0 es otra cuenta
     */
    private function compareDestination(string $kind, array $d, array $account): array
    {
        $label = self::KIND_LABEL[$kind];
        $list = [];
        if (in_array($kind, ['pago_movil', 'transferencia'], true)) {
            $again = $d['relectura'] ?? [];
            $fields = $kind === 'pago_movil'
                ? ['el teléfono' => [[$d['receptor_telefono'] ?? null, $again['telefono'] ?? null], $account['phone'], 'phone']]
                : ['el número de cuenta' => [[$d['receptor_cuenta'] ?? null, $again['cuenta'] ?? null], $account['account'], 'digits']];
            $fields['la cédula'] = [[$d['receptor_identificacion'] ?? null, $again['cedula'] ?? null], $account['doc'], 'id'];
            $grades = [];
            foreach ($fields as $name => [$readings, $expected, $type]) {
                // Primera y segunda lectura: vale la que mejor coincide (la segunda lee dígito por dígito)
                $best = null;
                foreach (array_filter($readings, fn ($seen) => $seen !== null) as $seen) {
                    $grade = $this->compareId($seen, $expected, $type);
                    if ($best === null || self::GRADE_RANK[$grade] > self::GRADE_RANK[$best[0]]) {
                        $best = [$grade, $seen];
                    }
                }
                $grades[$name] = $best ?? ['unknown', null];
            }
            $levels = array_column($grades, 0);
            $read = fn (string $grade) => implode(' y ', array_map(fn ($name) => "{$name} de destino es {$grades[$name][1]}", array_keys(array_filter($grades, fn ($g) => $g[0] === $grade))));
            $wrong = false;
            if (in_array('exact', $levels, true)) {
                $rank = 3;
                // El banco solo acepta el pago si el teléfono (o la cuenta) y la cédula son del mismo afiliado: si uno
                // coincide exacto con la empresa, el pago llegó a la empresa y una diferencia de 1 o 2 dígitos en el
                // otro es de lectura (2026-10-03: la IA leía 0412-244948 por un teléfono con tres "4" seguidos).
                // Si el otro es otro número del todo, el banco no lo habría aceptado: puede ser un capture editado.
                if (in_array('different', $levels, true)) {
                    $okName = array_key_first(array_filter($grades, fn ($g) => $g[0] === 'exact'));
                    $list[] = $this->warn('Revisa la foto: ' . $read('different') . ', aunque ' . $okName . ' sí es ' . (str_starts_with($okName, 'la ') ? 'la' : 'el') . ' de la empresa (el banco no acepta un pago así).', 'destination');
                }
            } elseif (in_array('different', $levels, true)) {
                $rank = 0;
                $wrong = true;
                $list[] = $this->fail(($kind === 'pago_movil' ? 'El pago móvil' : 'La transferencia') . ' no fue a la cuenta de la empresa: ' . $read('different') . '.', 'destination');
            } elseif (in_array('near', $levels, true)) {
                $rank = 2;
                $list[] = $this->warn('No se lee con seguridad a quién se hizo el pago: se leyó que ' . $read('near') . ', parecido al de la empresa. Revisa la foto.', 'destination');
            } else {
                $rank = 1;
                $list[] = $this->warn("No se ve a qué " . ($kind === 'pago_movil' ? 'teléfono' : 'cuenta') . ' ni cédula se hizo el pago.', 'destination');
            }
            $bank = $d['banco_destino'] ?? null;
            if (!$wrong && $bank && $account['bank'] && !$this->sameBank($bank, $account['bank'])) {
                $list[] = $this->warn("El banco de destino dice «{$bank}» y la cuenta de la empresa es de {$account['bank']}.", 'bank');
            }
        } else {
            $same = $this->sameMasked(mb_strtolower(trim((string) ($d['receptor_correo'] ?? ''))) ?: null, mb_strtolower((string) $account['email']) ?: null, true);
            $rank = $same === true ? 3 : ($same === null ? 1 : 0);
            if ($same === false) {
                $list[] = $this->fail("El pago por {$label} no fue a la cuenta de la empresa: el correo de destino es {$d['receptor_correo']}.", 'destination');
            } elseif ($same === null) {
                $list[] = $this->warn("No se ve a qué correo se hizo el pago por {$label}.", 'destination');
            }
        }

        return ['rank' => $rank, 'issues' => $list];
    }

    private function checkDate(array $d, $uploadedAt): ?array
    {
        if (empty($d['fecha']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['fecha'])) {
            return null;
        }
        $date = Carbon::parse($d['fecha'])->startOfDay();
        $uploaded = Carbon::parse($uploadedAt ?? now())->startOfDay();
        if ($date->gt($uploaded->copy()->addDay())) {
            return $this->warn('La fecha del comprobante (' . $date->format('d/m/Y') . ') es posterior a cuando se subió.', 'date');
        }
        $days = $date->diffInDays($uploaded);
        if ($days > self::MAX_AGE_DAYS) {
            return $this->warn('El comprobante es del ' . $date->format('d/m/Y') . ", de {$days} días antes de subirlo.", 'date');
        }

        return null;
    }

    /**
     * Lo que suman los comprobantes de un método contra lo registrado (amountVerdict), y lo que se guarda en la orden
     * (§9 del documento de Fran): la venta, lo recibido y el excedente, cada uno por su lado. El excedente no cambia
     * el total de la orden ni las ganancias.
     * @return array{0: array<int, array>, 1: ?array} issue por id de comprobante, y la venta y lo recibido (null si
     *   no se sabe cuánto se recibió)
     */
    private function checkAmount(string $kind, array $checks, Collection $payments): array
    {
        $kindPayments = $this->familyPayments($payments, $kind);
        if ($kindPayments->isEmpty()) {
            return [[], null];
        }
        $ves = in_array($kind, self::VES_KINDS, true);
        $kind = self::METHOD_KIND[$kindPayments->first()->method]; // el nombre, como está registrado el pago
        $out = [];
        if ($ves && $kindPayments->contains(fn ($p) => !(float) $p->rate)) {
            return [[], null]; // sin tasa guardada no se puede pasar a bolívares
        }
        $expected = round($ves ? $kindPayments->sum(fn ($p) => (float) $p->amount * (float) $p->rate) : (float) $kindPayments->sum('amount'), 2);
        $readings = [];
        foreach ($checks as $c) {
            $readings[$c->id] = $this->amountReadings($c);
            if (!$readings[$c->id]) {
                $out[$c->id] = $this->warn('No se lee el monto del comprobante.', 'amount');
            } elseif ($ves && $c->currency && $c->currency !== 'VES') {
                $out[$c->id] = $this->warn("El comprobante está en {$c->currency} y el pago por " . self::KIND_LABEL[$kind] . ' se registra en bolívares.', 'currency');
            }
        }
        if (count(array_filter($readings)) < count($checks)) {
            return [$out, null]; // si falta leer algún monto no se puede sumar
        }
        $fits = fn (float $got) => self::amountVerdict($expected, $got, $ves) === 'ok';
        $first = array_map(fn ($r) => $r[0], $readings);
        // Con segunda lectura hay más de una combinación: si alguna cuadra, esa es la buena (la IA no recibe el
        // monto esperado, así que una lectura que cae justo en él no es casualidad)
        $chosen = Arr::first($this->combinations($readings), fn ($combo) => $fits(array_sum($combo))) ?? $first;
        foreach ($checks as $c) {
            $c->amount = $chosen[$c->id]; // el que se muestra como leído
        }
        $received = round(array_sum($chosen), 2);
        $summary = [
            'metodo' => $kind,
            'moneda' => $ves ? 'VES' : ($checks[0]->currency ?: 'USD'),
            'venta' => $expected,
            'recibido' => $received,
            'excedente' => max(0, round($received - $expected, 2)),
        ];
        if ($fits(array_sum($chosen))) {
            return [$out, $summary];
        }

        $got = array_sum($first);
        $fmt = fn ($n) => $ves ? 'Bs. ' . $this->bs($n) : '$' . $this->usd($n);
        $label = self::KIND_LABEL[$kind];
        $registered = $ves ? ' ($' . $this->usd((float) $kindPayments->sum('amount')) . ' a la tasa del pago)' : '';
        if (array_filter($readings, fn ($r) => count($r) > 1)) {
            // Las dos lecturas no coinciden y ninguna cuadra: que lo vea una persona, sin bloquear. No se sabe
            // cuánto se recibió, así que no se guarda.
            $second = array_sum(array_map(fn ($r) => $r[count($r) - 1], $readings));
            $text = 'Lectura dudosa: ' . (count($checks) > 1
                ? "los comprobantes de {$label} suman " . $fmt($got) . ' según la primera lectura y ' . $fmt($second) . ' según la segunda'
                : 'el monto se leyó como ' . $fmt($got) . ' y como ' . $fmt($second))
                . ", y el pago por {$label} es de " . $fmt($expected) . $registered . '. Revisa la foto.';
            foreach ($checks as $c) {
                $out[$c->id] ??= $this->warn($text, 'amount');
            }

            return [$out, null];
        }
        $paid = (count($checks) > 1 ? "los comprobantes de {$label} suman " : 'el comprobante dice ') . $fmt($got);
        if (self::amountVerdict($expected, $got, $ves) === 'short') {
            // §31, casos 3 y 6: "Monto insuficiente"
            $min = $ves && floor($expected) != $expected ? ' El mínimo es ' . $fmt(floor($expected)) . ' (sin los decimales).' : '';
            $text = "Monto insuficiente: {$paid} y el pago por {$label} es de " . $fmt($expected) . $registered . '.' . $min;
            foreach ($checks as $c) {
                $out[$c->id] = $this->fail($text, 'amount');
            }
        } else {
            // §8: más del 5 % no se rechaza; se acepta y se clasifica como "Validado con advertencia"
            $text = "Validado con advertencia: el cliente pagó un monto significativamente superior al esperado ({$paid} y el pago por {$label} es de "
                . $fmt($expected) . $registered . ', un ' . $this->percent($got / $expected - 1) . ' más).';
            foreach ($checks as $c) {
                $out[$c->id] ??= $this->warn($text, 'overpaid');
            }
        }

        return [$out, $summary];
    }

    /** 0.1 → "10 %", 0.052 → "5,2 %". */
    private function percent(float $ratio): string
    {
        return str_replace(',0', '', number_format(round($ratio * 100, 1), 1, ',', '.')) . ' %';
    }

    /** Los montos leídos de un comprobante: el de la primera lectura y, si la segunda dio otro, también ese. */
    private function amountReadings(ReceiptCheck $c): array
    {
        $readings = array_filter([$c->extracted['monto'] ?? null, $c->extracted['relectura']['monto'] ?? null], fn ($v) => $v !== null);

        return array_values(array_unique(array_map(fn ($v) => round((float) $v, 2), $readings), SORT_REGULAR));
    }

    /**
     * Las combinaciones de lecturas de varios comprobantes (uno o dos montos cada uno), empezando por la de las
     * primeras lecturas. @return array<int, array<int, float>> monto por id de revisión
     */
    private function combinations(array $readings): array
    {
        $combos = [[]];
        foreach ($readings as $id => $amounts) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($amounts as $amount) {
                    $next[] = $combo + [$id => $amount];
                }
            }
            $combos = $next;
        }

        return $combos;
    }

    // --- Cuentas de la empresa ---

    /**
     * Las cuentas de "Cuentas bancarias" con su método y los datos que se comparan. El método lo dice cada cuenta
     * (documento de Fran del 2026-10-06, §2): no se adivina por el nombre. Puede haber varias por método (§13).
     */
    private function accounts(): Collection
    {
        return CompanyAccount::whereNotNull('method')->get()->map(function (CompanyAccount $a) {
            $field = function (string $needle) use ($a) {
                foreach ((array) $a->details as $row) {
                    if (is_array($row) && str_contains($this->norm((string) ($row['label'] ?? '')), $needle)) {
                        return (string) ($row['value'] ?? '');
                    }
                }
                return null;
            };

            return [
                'id' => $a->id,
                'kind' => $a->method,
                'active' => (bool) $a->is_active,
                'phone' => $field('telefono'),
                'doc' => $field('cedula') ?? $field('rif'),
                'account' => $field('cuenta'),
                'bank' => $field('banco'),
                'email' => $field('correo') ?? $field('email'),
            ];
        });
    }

    // --- Comparaciones ---

    /** Solo dígitos y "*" (dígitos que el banco tapa). Teléfonos al formato 04XX: +58 412… → 0412…. */
    private function clean(?string $value, string $type): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = preg_replace('/[^0-9*]/', '', preg_replace('/[xX•·●]/u', '*', $value));
        if ($type === 'phone') {
            if (str_starts_with($v, '58') && strlen($v) === 12) {
                $v = '0' . substr($v, 2);
            } elseif (strlen($v) === 10 && str_starts_with($v, '4')) {
                $v = '0' . $v;
            }
        }

        return $v === '' ? null : $v;
    }

    /**
     * Compara un dato de destino (teléfono, cédula o cuenta) con el de la empresa:
     * exact (igual, o igual en lo que el banco deja ver), near (a 1 o 2 dígitos: error de lectura),
     * unknown (no se ve, la IA no está segura o no tiene forma de ese dato) o different.
     */
    private function compareId(?string $seen, ?string $expected, string $type): string
    {
        if ($seen === null || trim($seen) === '' || str_contains($seen, '?')) {
            return 'unknown';
        }
        $a = $this->clean($seen, $type);
        $b = $this->clean($expected, $type);
        if ($a === null || $b === null) {
            return 'unknown';
        }
        $masked = $this->sameMasked($a, $b);
        if ($masked === true) {
            return 'exact';
        }
        if ($masked === null) {
            return 'unknown';
        }
        if (!str_contains($a, '*') && levenshtein($a, $b) <= 2) {
            return 'near';
        }
        // Largo de un teléfono (04XX + 7), una cédula o una cuenta de 20 dígitos; si no lo tiene, la IA leyó otra cosa
        [$min, $max] = ['phone' => [10, 12], 'id' => [6, 9], 'digits' => [16, 20]][$type];
        $length = strlen($a);

        return $length >= $min && $length <= $max ? 'different' : 'unknown';
    }

    /**
     * true si coinciden, false si no, null si no se puede saber (no se ve o se ve muy poco).
     * Lo tapado con "*" vale por cualquier cosa.
     */
    private function sameMasked(?string $seen, ?string $expected, bool $text = false): ?bool
    {
        if ($seen === null || $expected === null || $seen === '' || $expected === '') {
            return null;
        }
        $seen = $text ? preg_replace('/[•·●]/u', '*', $seen) : $seen;
        if (!str_contains($seen, '*')) {
            return $seen === $expected;
        }
        $visible = strlen(str_replace('*', '', $seen));
        if ($visible < ($text ? 3 : 4)) {
            return null;
        }
        $pattern = implode($text ? '.*' : '[0-9]*', array_map(fn ($part) => preg_quote($part, '/'), explode('*', $seen)));

        return (bool) preg_match('/^' . $pattern . '$/u', $expected);
    }

    private function sameBank(string $seen, string $expected): bool
    {
        $seenNorm = $this->norm($seen);
        if (preg_match('/\b(\d{4})\b/', $expected, $m) && str_contains($seenNorm, $m[1])) {
            return true;
        }
        // "Banco Universal S.A.C.A." solo no dice qué banco es: no se compara
        $generic = ['banco', 'universal', 's', 'a', 'c', 'sa', 'ca', 'saca', 'bank'];
        if (!array_filter(preg_split('/[^a-z0-9]+/', $seenNorm), fn ($w) => $w !== '' && !in_array($w, $generic, true))) {
            return true;
        }
        $name = trim(preg_replace('/\(.*?\)|\d+/', '', $this->norm($expected)));

        return $name !== '' && str_contains($seenNorm, $name);
    }

    private function norm(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', \Illuminate\Support\Str::ascii($s))));
    }

    private function bs(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }

    private function usd(float $n): string
    {
        return number_format($n, 2, '.', ',');
    }

    /**
     * $code: kind, destination, account, bank, amount, overpaid, currency, duplicate, state, date, cash, unreadable
     * (para decidir qué se suaviza y qué se relee: destination, amount y overpaid). overpaid es el "Validado con
     * advertencia" del documento de Fran (§8): la ficha lo muestra con ese nombre.
     */
    private function fail(string $text, string $code = 'other'): array
    {
        return ['level' => 'fail', 'text' => $text, 'code' => $code];
    }

    private function warn(string $text, string $code = 'other'): array
    {
        return ['level' => 'warning', 'text' => $text, 'code' => $code];
    }
}
