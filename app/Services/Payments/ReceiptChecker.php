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

    /** Revisa otra vez todos los comprobantes leídos de la orden, sin llamar a la IA (p. ej. si cambiaron los pagos). */
    public function evaluateOrder(Order $order): void
    {
        $checks = ReceiptCheck::where('order_id', $order->id)->whereNotNull('kind')->orderBy('id')->get();
        if ($checks->isEmpty()) {
            return;
        }
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
                $list[] = $this->warn('No se lee bien el comprobante' . (!empty($d['motivo_ilegible']) ? " ({$d['motivo_ilegible']})" : '') . '. Pide al cliente una foto o un capture más claro.');
            } elseif ($kind === 'otro') {
                $list[] = $this->fail('La imagen no parece un comprobante de pago ni una foto de billetes.');
            } elseif ($kind === 'efectivo') {
                $list = array_merge($list, $this->checkCash($d, $payments, $methodsText));
            } else {
                $kindPayments = $payments->filter(fn ($p) => (self::METHOD_KIND[$p->method] ?? null) === $kind);
                if ($kindPayments->isEmpty()) {
                    $list[] = $this->fail('El comprobante es de ' . self::KIND_LABEL[$kind] . ", pero el pago está registrado como {$methodsText}.");
                } else {
                    $list = array_merge($list, $this->checkDestination($kind, $d, $accounts->get($kind)));
                }
                if (($d['estado'] ?? null) === 'rechazada') {
                    $list[] = $this->fail('El comprobante dice que el pago fue rechazado.');
                } elseif (($d['estado'] ?? null) === 'pendiente') {
                    $list[] = $this->warn('El comprobante dice que el pago está pendiente.');
                }
                if ($dateIssue = $this->checkDate($d, $check->created_at)) {
                    $list[] = $dateIssue;
                }

                $duplicate = false;
                if ($check->reference) {
                    if (isset($seenRefs[$check->reference])) {
                        $duplicate = true;
                        $list[] = $this->warn('Este comprobante está repetido en la orden (misma referencia); cuenta una sola vez.');
                    } else {
                        $seenRefs[$check->reference] = true;
                        $other = ReceiptCheck::where('reference', $check->reference)->where('order_id', '!=', $order->id)
                            ->where('kind', $kind)->with('order:id,name')->first();
                        if ($other) {
                            $list[] = $this->fail("Esta referencia ({$check->reference}) ya está en un comprobante de la orden " . ($other->order->name ?? "#{$other->order_id}") . '.');
                        }
                    }
                }
                // Para el monto solo cuenta lo que llegó de verdad: ni repetidos ni lo que no cuadra
                if (!$duplicate && !in_array('fail', array_column($list, 'level'), true)) {
                    $counted[$kind][] = $check;
                }
            }
            $issues[$check->id] = $list;
        }

        // Monto: lo que suman los comprobantes de cada método contra lo registrado
        foreach ($counted as $kind => $kindChecks) {
            foreach ($this->checkAmount($kind, $kindChecks, $payments) as $checkId => $issue) {
                $issues[$checkId][] = $issue;
            }
        }

        foreach ($checks as $check) {
            $list = $issues[$check->id] ?? [];
            $levels = array_column($list, 'level');
            $status = $check->kind === 'ilegible' ? ReceiptCheck::UNREADABLE
                : (in_array('fail', $levels, true) ? ReceiptCheck::FAIL
                    : (in_array('warning', $levels, true) ? ReceiptCheck::WARNING : ReceiptCheck::OK));
            if ($check->status !== $status || $check->issues !== $list) {
                $check->forceFill(['status' => $status, 'issues' => $list])->save();
            }
        }
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
            ->filter(fn ($k) => $k && $k !== 'efectivo')->unique();
        foreach ($kinds as $kind) {
            if (!$checks->contains(fn ($c) => $c->kind === $kind)) {
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
            return [$this->fail("La foto es de billetes, pero el pago está registrado como {$methodsText}.")];
        }
        $list = [];
        $currencyMethod = ['USD' => 'DOLARES_EFECTIVO', 'VES' => 'BOLIVARES_EFECTIVO', 'EUR' => 'EUROS_EFECTIVO'];
        $seen = $d['efectivo_moneda'] ?? null;
        if ($seen && isset($currencyMethod[$seen]) && !$cash->contains('method', $currencyMethod[$seen])) {
            $list[] = $this->warn('Los billetes parecen ser ' . self::METHOD_LABEL[$currencyMethod[$seen]] . ', pero el efectivo está registrado como ' . $cash->pluck('method')->unique()->map(fn ($m) => self::METHOD_LABEL[$m])->implode(' y ') . '.');
        }
        $total = $d['efectivo_total_visible'] ?? null;
        if ($seen === 'USD' && $total !== null) {
            $expected = (float) $cash->where('method', 'DOLARES_EFECTIVO')->sum('amount');
            if ($expected > 0 && $total + 0.5 < $expected) {
                $list[] = $this->warn('En la foto se ven $' . $this->usd($total) . ' en billetes y el pago en efectivo es de $' . $this->usd($expected) . '.');
            }
        }

        return $list;
    }

    private function checkDestination(string $kind, array $d, ?array $account): array
    {
        $label = self::KIND_LABEL[$kind];
        if (!$account) {
            return [$this->warn("No hay una cuenta de {$label} en Cuentas bancarias para comparar.")];
        }
        $list = $account['active'] ? [] : [$this->warn("La cuenta de {$label} está desactivada en Cuentas bancarias.")];

        if (in_array($kind, ['pago_movil', 'transferencia'], true)) {
            $checks = $kind === 'pago_movil'
                ? ['el teléfono' => [$d['receptor_telefono'] ?? null, $account['phone'], 'phone']]
                : ['el número de cuenta' => [$d['receptor_cuenta'] ?? null, $account['account'], 'digits']];
            $checks['la cédula'] = [$d['receptor_identificacion'] ?? null, $account['id'], 'id'];
            $wrong = [];
            $matched = 0;
            foreach ($checks as $name => [$seen, $expected, $type]) {
                $same = $this->sameMasked($this->clean($seen, $type), $this->clean($expected, $type));
                if ($same === false) {
                    $wrong[] = "{$name} de destino es {$seen}";
                } elseif ($same === true) {
                    $matched++;
                }
            }
            if ($wrong) {
                $list[] = $this->fail(($kind === 'pago_movil' ? 'El pago móvil' : 'La transferencia') . ' no fue a la cuenta de la empresa: ' . implode(' y ', $wrong) . '.');
            } elseif ($matched === 0) {
                $list[] = $this->warn("No se ve a qué " . ($kind === 'pago_movil' ? 'teléfono' : 'cuenta') . ' ni cédula se hizo el pago.');
            }
            $bank = $d['banco_destino'] ?? null;
            if (!$wrong && $bank && $account['bank'] && !$this->sameBank($bank, $account['bank'])) {
                $list[] = $this->warn("El banco de destino dice «{$bank}» y la cuenta de la empresa es de {$account['bank']}.");
            }
        } else {
            $same = $this->sameMasked(mb_strtolower(trim((string) ($d['receptor_correo'] ?? ''))) ?: null, mb_strtolower((string) $account['email']) ?: null, true);
            if ($same === false) {
                $list[] = $this->fail("El pago por {$label} no fue a la cuenta de la empresa: el correo de destino es {$d['receptor_correo']}.");
            } elseif ($same === null) {
                $list[] = $this->warn("No se ve a qué correo se hizo el pago por {$label}.");
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
            return $this->warn('La fecha del comprobante (' . $date->format('d/m/Y') . ') es posterior a cuando se subió.');
        }
        $days = $date->diffInDays($uploaded);
        if ($days > self::MAX_AGE_DAYS) {
            return $this->warn('El comprobante es del ' . $date->format('d/m/Y') . ", de {$days} días antes de subirlo.");
        }

        return null;
    }

    /** @return array<int, array> issue por id de comprobante */
    private function checkAmount(string $kind, array $checks, Collection $payments): array
    {
        $kindPayments = $payments->filter(fn ($p) => (self::METHOD_KIND[$p->method] ?? null) === $kind);
        if ($kindPayments->isEmpty()) {
            return [];
        }
        $ves = in_array($kind, self::VES_KINDS, true);
        $out = [];
        if ($ves && $kindPayments->contains(fn ($p) => !(float) $p->rate)) {
            return []; // sin tasa guardada no se puede pasar a bolívares
        }
        $expected = $ves ? $kindPayments->sum(fn ($p) => (float) $p->amount * (float) $p->rate) : (float) $kindPayments->sum('amount');
        $readable = array_filter($checks, fn ($c) => $c->amount !== null);
        foreach ($checks as $c) {
            if ($c->amount === null) {
                $out[$c->id] = $this->warn('No se lee el monto del comprobante.');
            } elseif ($ves && $c->currency && $c->currency !== 'VES') {
                $out[$c->id] = $this->warn("El comprobante está en {$c->currency} y el pago por " . self::KIND_LABEL[$kind] . ' se registra en bolívares.');
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
                $out[$c->id] = $this->fail($text);
            }
        } elseif ($got > $expected * (1 + self::OVER_TOLERANCE) + 1) {
            $text = (count($checks) > 1 ? "Los comprobantes de {$label} suman " : 'El comprobante dice ') . $fmt($got) . ', más que el pago registrado (' . $fmt($expected) . '). Revisa el monto del pago.';
            foreach ($checks as $c) {
                $out[$c->id] ??= $this->warn($text);
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

    private function fail(string $text): array
    {
        return ['level' => 'fail', 'text' => $text];
    }

    private function warn(string $text): array
    {
        return ['level' => 'warning', 'text' => $text];
    }
}
