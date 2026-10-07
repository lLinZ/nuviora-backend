<?php

use App\Services\Payments\ReceiptChecker;

// Documento de Fran del 2026-10-06 (Módulo 1): la regla del monto al validar un comprobante (§5 a §8) y los casos
// del §31 que tienen que funcionar.

describe('Regla del monto del comprobante', function () {
    it('en bolívares perdona solo los decimales (§5, §31 casos 1 a 3)', function () {
        expect(ReceiptChecker::amountVerdict(33550.71, 33550, true))->toBe('ok');       // caso 1: redondeo hacia abajo
        expect(ReceiptChecker::amountVerdict(33550.71, 33551, true))->toBe('ok');       // caso 2: hacia arriba
        expect(ReceiptChecker::amountVerdict(33550.71, 33549, true))->toBe('short');    // caso 3: falta más que los decimales
        expect(ReceiptChecker::amountVerdict(33550.71, 33549.99, true))->toBe('short'); // el ejemplo del §5
    });

    it('sin decimales en lo esperado, no hay tolerancia hacia abajo (§5)', function () {
        expect(ReceiptChecker::amountVerdict(33550.00, 33550, true))->toBe('ok');
        expect(ReceiptChecker::amountVerdict(33550.00, 33549.99, true))->toBe('short');
    });

    it('en otras monedas los decimales cuentan (§6, §31 caso 6)', function () {
        expect(ReceiptChecker::amountVerdict(32.57, 32.56, false))->toBe('short');
        expect(ReceiptChecker::amountVerdict(32.57, 32.57, false))->toBe('ok');
    });

    it('hasta un 5 % más es válido (§7, §31 caso 4)', function () {
        expect(ReceiptChecker::amountVerdict(10000, 10400, true))->toBe('ok');
        expect(ReceiptChecker::amountVerdict(10000, 10500, true))->toBe('ok');      // "Hasta: 10.500 Bs"
        expect(ReceiptChecker::amountVerdict(32.57, 34.19, false))->toBe('ok');     // 32,57 + 5 % = 34,1985
    });

    it('más del 5 % es válido con advertencia, no se rechaza (§8, §31 caso 5)', function () {
        expect(ReceiptChecker::amountVerdict(10000, 11000, true))->toBe('over');
        expect(ReceiptChecker::amountVerdict(10000, 10500.01, true))->toBe('over');
        expect(ReceiptChecker::amountVerdict(32.57, 34.20, false))->toBe('over');
    });

    it('con pagos divididos se compara la suma (§10, §31 casos 7 y 8)', function () {
        expect(ReceiptChecker::amountVerdict(40000, 20000 + 20000, true))->toBe('ok'); // dos pagos móviles de 20.000
        expect(ReceiptChecker::amountVerdict(25000, 25000, true))->toBe('ok');         // la parte en pago móvil de 15.000 efectivo + 25.000
    });
});
