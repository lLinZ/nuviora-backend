<?php

use App\Models\BankStatementRow;
use App\Services\Reconciliation\StatementReader;

// Documento de Fran del 2026-10-06 (Módulo 1, §17 y §19): el extracto se lleva a una estructura estándar con reglas
// fijas (sin IA) y la referencia se compara sin espacios, guiones ni ceros iniciales.

$t = fn (string $text, ?float $number = null) => ['text' => $text, 'number' => $number];

describe('Lector de extractos', function () use ($t) {
    it('lee montos con los formatos de Venezuela y de otros países', function () use ($t) {
        $r = new StatementReader();
        expect($r->amount($t('33.550,71')))->toBe(33550.71);
        expect($r->amount($t('33,550.71')))->toBe(33550.71);
        expect($r->amount($t('Bs. 1.234,56')))->toBe(1234.56);
        expect($r->amount($t('33.551')))->toBe(33551.0);     // un solo punto con 3 dígitos separa los miles
        expect($r->amount($t('32,57')))->toBe(32.57);
        expect($r->amount($t('-1.234,56')))->toBe(-1234.56);
        expect($r->amount($t('1.234,56-')))->toBe(-1234.56);
        expect($r->amount($t('(1.234,56)')))->toBe(-1234.56);
        expect($r->amount($t('', 1500.5)))->toBe(1500.5);     // número de Excel
        expect($r->amount($t('')))->toBeNull();
        expect($r->amount($t('Saldo')))->toBeNull();
    });

    it('lee fechas día/mes, año-mes-día y de Excel', function () use ($t) {
        $r = new StatementReader();
        expect($r->date($t('05/10/2026')))->toBe('2026-10-05');
        expect($r->date($t('5-10-26')))->toBe('2026-10-05');
        expect($r->date($t('2026-10-05 14:32:10')))->toBe('2026-10-05');
        expect($r->date($t('10/05/2026'), false))->toBe('2026-10-05');
        expect($r->date($t('', 46300)))->toBe('2026-10-05');
        expect($r->date($t('31/02/2026')))->toBeNull();
        expect($r->date($t('Fecha')))->toBeNull();
    });

    it('reconoce las columnas por su nombre aunque haya filas antes de los títulos', function () use ($t) {
        $rows = [
            1 => [$t('Banesco Banco Universal'), $t(''), $t('')],
            2 => [$t('Movimientos de la cuenta 0134-****-2851'), $t(''), $t('')],
            4 => [$t('Fecha'), $t('Referencia'), $t('Descripción'), $t('Débito'), $t('Crédito'), $t('Saldo')],
        ];
        $format = (new StatementReader())->detect($rows);
        expect($format['header_row'])->toBe(4);
        expect($format['columns'])->toBe(['date' => 0, 'reference' => 1, 'description' => 2, 'debit' => 3, 'credit' => 4]);
        expect($format['mode'])->toBe('credit');
    });

    it('solo devuelve los ingresos y cuenta los egresos (§23)', function () use ($t) {
        $rows = [
            1 => [$t('Fecha'), $t('Nro. Referencia'), $t('Concepto'), $t('Monto')],
            2 => [$t('05/10/2026'), $t('000849275'), $t('PAGO MOVIL'), $t('33.551,00')],
            3 => [$t('05/10/2026'), $t('112233'), $t('COMPRA POS'), $t('-500,00')],
            4 => [$t('05/10/2026'), $t('445566'), $t('TRANSFERENCIA'), $t('1.000,00')],
            5 => [$t(''), $t(''), $t('Saldo final'), $t('')],
        ];
        $r = new StatementReader();
        $result = $r->credits($rows, $r->detect($rows));
        expect($result['read'])->toBe(3);
        expect($result['debits'])->toBe(1);
        expect(array_column($result['credits'], 'amount'))->toBe([33551.0, 1000.0]);
        expect($result['credits'][0]['reference_norm'])->toBe('849275'); // sin ceros a la izquierda (§19)
        expect($result['credits'][0]['line'])->toBe(2);                 // la fila del archivo (§17)
    });

    it('con una columna de tipo, ingreso es lo marcado como crédito', function () use ($t) {
        $rows = [
            1 => [$t('Fecha'), $t('Referencia'), $t('Tipo'), $t('Monto')],
            2 => [$t('05/10/2026'), $t('111111'), $t('C'), $t('100,00')],
            3 => [$t('05/10/2026'), $t('222222'), $t('D'), $t('200,00')],
        ];
        $r = new StatementReader();
        $format = $r->detect($rows);
        expect($format['mode'])->toBe('type');
        expect(array_column($r->credits($rows, $format)['credits'], 'reference'))->toBe(['111111']);
    });

    it('normaliza la referencia: espacios, guiones y ceros iniciales (§19)', function () {
        expect(BankStatementRow::normalizeReference(' 0012-3456 789 '))->toBe('123456789');
        expect(BankStatementRow::normalizeReference('ZN-445566'))->toBe('445566');
        expect(BankStatementRow::normalizeReference('000'))->toBeNull();
        expect(BankStatementRow::normalizeReference(null))->toBeNull();
    });
});
