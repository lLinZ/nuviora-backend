<?php

namespace App\Console\Commands;

use App\Services\Finance\CashLedger;
use Illuminate\Console\Command;

/**
 * La caja (Módulo 4 de Fran, entrega 3).
 * - --resync (cada noche y al desplegar): vuelve a cuadrar todos los movimientos automáticos con su origen (pagos de la
 *   conciliación y órdenes entregadas), por si algún cambio no pasó por los observers.
 * - Sin opciones: muestra el saldo de cada cuenta.
 */
class FinanceCash extends Command
{
    protected $signature = 'finance:cash {--resync}';

    protected $description = 'Mantiene la caja al día con la operación (pagos conciliados, efectivo de agencias y vueltos)';

    public function handle(CashLedger $ledger): int
    {
        if ($this->option('resync')) {
            $done = $ledger->resyncAll();
            $this->info("Revisadas {$done['orders']} órdenes, {$done['items']} pagos de la conciliación y {$done['movements']} movimientos a mano.");

            return self::SUCCESS;
        }
        $this->table(['Cuenta', 'Moneda', 'Saldo inicial', 'Movimientos', 'Saldo', 'Por conciliar'], $ledger->balances()->map(fn ($a) => [
            $a['name'], $a['currency'], $a['opening_balance'] === null ? '(sin poner)' : number_format($a['opening_balance'], 2, ',', '.'),
            $a['movements'], number_format($a['balance'], 2, ',', '.'), number_format($a['pending'], 2, ',', '.'),
        ])->all());

        return self::SUCCESS;
    }
}
