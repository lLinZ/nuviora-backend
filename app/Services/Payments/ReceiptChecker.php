<?php

namespace App\Services\Payments;

use App\Models\CompanyAccount;
use App\Models\Order;
use App\Models\ReceiptCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Compara lo que la IA leyó de cada comprobante con los pagos de la orden y con las cuentas de la empresa
 * (Fran, 2026-10-03). La IA solo lee; aquí se decide:
 * - El tipo de comprobante tiene que coincidir con el método registrado (billetes = efectivo, capture de banco
 *   en bolívares = pago móvil, etc.).
 * - Un pago digital tiene que ir a la cuenta de la empresa (teléfono, cédula, número de cuenta o correo).
 * - Los comprobantes de un método tienen que sumar lo registrado (los bolívares, con la tasa del pago).
 * - La misma referencia no puede estar en otra orden.
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

    /** Margen para el monto: 2 % menos (redondeos y tasas) o 5 % más. */
    private const SHORT_TOLERANCE = 0.02;
    private const OVER_TOLERANCE = 0.05;

    private const MAX_AGE_DAYS = 3;

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
            'amount' => $d['tipo'] === 'efectivo' ? ($d['efectivo_total_visible'] ?? null) : ($d['monto'] ?? null),
            'currency' => $d['tipo'] === 'efectivo' ? ($d['efectivo_moneda'] ?? null) : ($d['moneda'] ?? null),
            'model' => $result['model'] ?? null,
            'response_id' => $result['response_id'] ?? null,
            'input_tokens' => $result['input_tokens'] ?? null,
            'output_tokens' => $result['output_tokens'] ?? null,
            'error' => null,
        ])->save();

        $this->evaluateOrder($check->order);
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
        $checks = ReceiptCheck::where('order_id', $order->id)->whereNotNull('kind')->with('receipt:id,created_at')->orderBy('id')->get();
        if ($checks->isEmpty()) {
            return;
        }
        $others = []; // órdenes con el mismo comprobante: también se marcan
        $payments = $order->payments()->get(['method', 'amount', 'rate']);
        $accounts = $this->accounts();
        $methodsText = $payments->isEmpty() ? 'nada (no hay pagos registrados)'
            : $payments->pluck('method')->unique()->map(fn ($m) => self::METHOD_LABEL[$m] ?? $m)->implode(' y ');

        $issues = [];
        $seenRefs = [];
        $counted = []; // por tipo, los comprobantes que suman al monto (sin repetidos)
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
                    $list = array_merge($list, $this->checkDestination($kind, $d, $accounts->get($kind)));
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
                if ($check->reference) {
                    if (isset($seenRefs[$check->reference])) {
                        $duplicate = true;
                        $list[] = $this->warn('Este comprobante está repetido en la orden (misma referencia); cuenta una sola vez.', 'duplicate');
                    } else {
                        $seenRefs[$check->reference] = true;
                        $sameRef = ReceiptCheck::where('reference', $check->reference)->where('order_id', '!=', $order->id)
                            ->whereIn('kind', $this->familyKinds($kind))->with('order:id,name')->get();
                        if ($sameRef->isNotEmpty()) {
                            $names = $sameRef->map(fn ($o) => $o->order->name ?? "#{$o->order_id}")->unique()->implode(', ');
                            $list[] = $this->fail("Este mismo comprobante (referencia {$check->reference}) está también en la orden {$names}: un pago no puede servir para dos órdenes.", 'duplicate');
                            foreach ($sameRef as $o) {
                                $others[$o->order_id] = true;
                            }
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

        // Monto: lo que suman los comprobantes de cada método contra lo registrado
        foreach ($counted as $kindChecks) {
            foreach ($this->checkAmount($kindChecks[0]->kind, $kindChecks, $payments) as $checkId => $issue) {
                $issues[$checkId][] = $issue;
            }
        }

        foreach ($checks as $check) {
            $list = $issues[$check->id] ?? [];
            // Si la IA dice que no ve bien la imagen, lo del monto y el destino queda para revisar, sin bloquear
            // (2026-10-03: en una foto chiquita y de lado leyó "2.214" donde decía 22.149,00)
            if (($check->extracted['confianza'] ?? 'alta') !== 'alta') {
                $list = array_map(fn ($i) => $i['level'] === 'fail' && in_array($i['code'] ?? null, ['destination', 'amount'], true)
                    ? ['level' => 'warning', 'text' => 'Lectura dudosa (la imagen no se ve del todo bien): ' . lcfirst($i['text']), 'code' => $i['code']]
                    : $i, $list);
            }
            $levels = array_column($list, 'level');
            $status = $check->kind === 'ilegible' ? ReceiptCheck::UNREADABLE
                : (in_array('fail', $levels, true) ? ReceiptCheck::FAIL
                    : (in_array('warning', $levels, true) ? ReceiptCheck::WARNING : ReceiptCheck::OK));
            if ($check->status !== $status || $check->issues !== $list) {
                $check->forceFill(['status' => $status, 'issues' => $list])->save();
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

        // Cada método digital tiene que tener al menos un comprobante de ese tipo. Si hay comprobantes sin
        // revisar (subidos antes de esta función o con error de la IA), no se exige.
        if ($receipts->contains(fn ($r) => !$r->check || in_array($r->check->status, [ReceiptCheck::PENDING, ReceiptCheck::ERROR], true))) {
            return null;
        }
        $kinds = $order->payments()->pluck('method')->map(fn ($m) => self::METHOD_KIND[$m] ?? null)
            ->filter(fn ($k) => $k && $k !== 'efectivo')->unique(fn ($k) => self::family($k));
        foreach ($kinds as $kind) {
            if (!$checks->contains(fn ($c) => self::family($c->kind) === self::family($kind))) {
                return 'No se puede entregar: falta el comprobante de ' . self::KIND_LABEL[$kind] . '. Ninguno de los comprobantes subidos es de ' . self::KIND_LABEL[$kind] . '.';
            }
        }

        return null;
    }

    // --- Revisiones ---

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
        $total = $d['efectivo_total_visible'] ?? null;
        if ($seen === 'USD' && $total !== null) {
            $expected = (float) $cash->where('method', 'DOLARES_EFECTIVO')->sum('amount');
            if ($expected > 0 && $total + 0.5 < $expected) {
                $list[] = $this->warn('En la foto se ven $' . $this->usd($total) . ' en billetes y el pago en efectivo es de $' . $this->usd($expected) . '.', 'cash');
            }
        }

        return $list;
    }

    private function checkDestination(string $kind, array $d, ?array $account): array
    {
        $label = self::KIND_LABEL[$kind];
        if (!$account) {
            return [$this->warn("No hay una cuenta de {$label} en Cuentas bancarias para comparar.", 'destination')];
        }
        $list = $account['active'] ? [] : [$this->warn("La cuenta de {$label} está desactivada en Cuentas bancarias.", 'destination')];

        if (in_array($kind, ['pago_movil', 'transferencia'], true)) {
            $fields = $kind === 'pago_movil'
                ? ['el teléfono' => [$d['receptor_telefono'] ?? null, $account['phone'], 'phone']]
                : ['el número de cuenta' => [$d['receptor_cuenta'] ?? null, $account['account'], 'digits']];
            $fields['la cédula'] = [$d['receptor_identificacion'] ?? null, $account['id'], 'id'];
            $grades = [];
            foreach ($fields as $name => [$seen, $expected, $type]) {
                $grades[$name] = [$this->compareId($seen, $expected, $type), $seen];
            }
            $levels = array_column($grades, 0);
            $read = fn (string $grade) => implode(' y ', array_map(fn ($name) => "{$name} de destino es {$grades[$name][1]}", array_keys(array_filter($grades, fn ($g) => $g[0] === $grade))));
            $wrong = false;
            if (in_array('exact', $levels, true)) {
                // El banco solo acepta el pago si el teléfono (o la cuenta) y la cédula son del mismo afiliado: si uno
                // coincide exacto con la empresa, el pago llegó a la empresa y una diferencia de 1 o 2 dígitos en el
                // otro es de lectura (2026-10-03: la IA leía 0412-244948 por un teléfono con tres "4" seguidos).
                // Si el otro es otro número del todo, el banco no lo habría aceptado: puede ser un capture editado.
                if (in_array('different', $levels, true)) {
                    $okName = array_key_first(array_filter($grades, fn ($g) => $g[0] === 'exact'));
                    $list[] = $this->warn('Revisa la foto: ' . $read('different') . ', aunque ' . $okName . ' sí es ' . (str_starts_with($okName, 'la ') ? 'la' : 'el') . ' de la empresa (el banco no acepta un pago así).', 'destination');
                }
            } elseif (in_array('different', $levels, true)) {
                $wrong = true;
                $list[] = $this->fail(($kind === 'pago_movil' ? 'El pago móvil' : 'La transferencia') . ' no fue a la cuenta de la empresa: ' . $read('different') . '.', 'destination');
            } elseif (in_array('near', $levels, true)) {
                $list[] = $this->warn('No se lee con seguridad a quién se hizo el pago: se leyó que ' . $read('near') . ', parecido al de la empresa. Revisa la foto.', 'destination');
            } else {
                $list[] = $this->warn("No se ve a qué " . ($kind === 'pago_movil' ? 'teléfono' : 'cuenta') . ' ni cédula se hizo el pago.', 'destination');
            }
            $bank = $d['banco_destino'] ?? null;
            if (!$wrong && $bank && $account['bank'] && !$this->sameBank($bank, $account['bank'])) {
                $list[] = $this->warn("El banco de destino dice «{$bank}» y la cuenta de la empresa es de {$account['bank']}.", 'destination');
            }
        } else {
            $same = $this->sameMasked(mb_strtolower(trim((string) ($d['receptor_correo'] ?? ''))) ?: null, mb_strtolower((string) $account['email']) ?: null, true);
            if ($same === false) {
                $list[] = $this->fail("El pago por {$label} no fue a la cuenta de la empresa: el correo de destino es {$d['receptor_correo']}.", 'destination');
            } elseif ($same === null) {
                $list[] = $this->warn("No se ve a qué correo se hizo el pago por {$label}.", 'destination');
            }
        }

        return $list;
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

    /** @return array<int, array> issue por id de comprobante */
    private function checkAmount(string $kind, array $checks, Collection $payments): array
    {
        $kindPayments = $this->familyPayments($payments, $kind);
        if ($kindPayments->isEmpty()) {
            return [];
        }
        $ves = in_array($kind, self::VES_KINDS, true);
        $kind = self::METHOD_KIND[$kindPayments->first()->method]; // el nombre, como está registrado el pago
        $out = [];
        if ($ves && $kindPayments->contains(fn ($p) => !(float) $p->rate)) {
            return []; // sin tasa guardada no se puede pasar a bolívares
        }
        $expected = $ves ? $kindPayments->sum(fn ($p) => (float) $p->amount * (float) $p->rate) : (float) $kindPayments->sum('amount');
        $readable = array_filter($checks, fn ($c) => $c->amount !== null);
        foreach ($checks as $c) {
            if ($c->amount === null) {
                $out[$c->id] = $this->warn('No se lee el monto del comprobante.', 'amount');
            } elseif ($ves && $c->currency && $c->currency !== 'VES') {
                $out[$c->id] = $this->warn("El comprobante está en {$c->currency} y el pago por " . self::KIND_LABEL[$kind] . ' se registra en bolívares.', 'amount');
            }
        }
        if (count($readable) < count($checks)) {
            return $out; // si falta leer algún monto no se puede sumar
        }
        $got = array_sum(array_map(fn ($c) => (float) $c->amount, $readable));
        $fmt = fn ($n) => $ves ? 'Bs. ' . $this->bs($n) : '$' . $this->usd($n);
        $label = self::KIND_LABEL[$kind];
        $registered = $ves ? ' ($' . $this->usd((float) $kindPayments->sum('amount')) . ' a la tasa del pago)' : '';
        if ($got < $expected * (1 - self::SHORT_TOLERANCE) - 1) {
            $text = (count($checks) > 1 ? "Los comprobantes de {$label} suman " : "El comprobante dice ") . $fmt($got) . " y el pago por {$label} es de " . $fmt($expected) . $registered . '.';
            foreach ($checks as $c) {
                $out[$c->id] = $this->fail($text, 'amount');
            }
        } elseif ($got > $expected * (1 + self::OVER_TOLERANCE) + 1) {
            $text = (count($checks) > 1 ? "Los comprobantes de {$label} suman " : 'El comprobante dice ') . $fmt($got) . ', más que el pago registrado (' . $fmt($expected) . '). Revisa el monto del pago.';
            foreach ($checks as $c) {
                $out[$c->id] ??= $this->warn($text, 'amount');
            }
        }

        return $out;
    }

    // --- Cuentas de la empresa ---

    /** Las cuentas de "Cuentas bancarias" por tipo, con los datos que se comparan. */
    private function accounts(): Collection
    {
        return CompanyAccount::all()->mapWithKeys(function (CompanyAccount $a) {
            $name = $this->norm($a->name);
            $kind = str_contains($name, 'pago') && str_contains($name, 'movil') ? 'pago_movil'
                : (str_contains($name, 'transfer') ? 'transferencia'
                    : collect(['binance', 'zinli', 'zelle', 'paypal'])->first(fn ($k) => str_contains($name, $k)));
            if (!$kind) {
                return [];
            }
            $field = function (string $needle) use ($a) {
                foreach ((array) $a->details as $row) {
                    if (is_array($row) && str_contains($this->norm((string) ($row['label'] ?? '')), $needle)) {
                        return (string) ($row['value'] ?? '');
                    }
                }
                return null;
            };

            return [$kind => [
                'active' => (bool) $a->is_active,
                'phone' => $field('telefono'),
                'id' => $field('cedula') ?? $field('rif'),
                'account' => $field('cuenta'),
                'bank' => $field('banco'),
                'email' => $field('correo') ?? $field('email'),
            ]];
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

    /** $code: kind, destination, amount, duplicate, state, date, cash, unreadable (para decidir qué se suaviza). */
    private function fail(string $text, string $code = 'other'): array
    {
        return ['level' => 'fail', 'text' => $text, 'code' => $code];
    }

    private function warn(string $text, string $code = 'other'): array
    {
        return ['level' => 'warning', 'text' => $text, 'code' => $code];
    }
}
