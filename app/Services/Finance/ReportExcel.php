<?php

namespace App\Services\Finance;

use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\Expense;
use App\Models\FinancialAuditLog;
use App\Models\FinancialTransaction as Tx;
use App\Models\RecurringExpensePayment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * La exportación detallada en Excel (Módulo 4 de Fran, §80: "para análisis, contador y auditoría") de cualquier período.
 * Sale de los datos vivos, con el mismo cálculo que las pantallas:
 * Resumen (§47, §58), Por producto, Por ciudad, Producto + ciudad (§50 a §52), Órdenes (§49: cada orden con sus costos),
 * Gastos (§24 a §28), Caja (§34), Por pagar y por cobrar (§44, §45) y Auditoría (§82).
 */
class ReportExcel
{
    private const MONEY = '#,##0.00;[Red]-#,##0.00';

    private const LEVELS = [
        'revenue' => 'Ingresos', 'revenue_main' => '  Ingreso principal', 'revenue_upsell' => '  Ingreso de upsells', 'revenue_refunded' => '  Reembolsado',
        'cogs' => 'COGS', 'logistics' => 'Logística atribuible', 'delivery' => 'Delivery', 'commission_sellers' => 'Comisiones vendedoras',
        'commission_leaders' => 'Comisión líderes', 'advertising' => 'Publicidad', 'other_variable' => 'Otros costes variables',
        'contribution' => 'Margen de contribución', 'fixed' => 'Gastos generales', 'operating_profit' => 'Beneficio operativo',
    ];

    public function __construct(private ProfitAndLoss $pnl, private CashLedger $ledger, private ExpenseLedger $expenses)
    {
    }

    /** Arma el libro y lo deja en un archivo temporal. Devuelve su ruta. */
    public function export(CarbonInterface $from, CarbonInterface $to): string
    {
        ini_set('memory_limit', '1024M');
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('Nuviora')->setTitle("Finanzas {$from->format('d/m/Y')} al {$to->format('d/m/Y')}");
        $book->removeSheetByIndex(0);

        $this->summary($book, $from, $to);
        foreach (['product' => 'Por producto', 'city' => 'Por ciudad', 'product_city' => 'Producto + ciudad'] as $by => $title) {
            $this->groups($book, $title, $this->pnl->report($from, $to, $by)['groups'] ?? []);
        }
        $this->orders($book, $from, $to);
        $this->expensesSheet($book, $from, $to);
        $this->cash($book, $from, $to);
        $this->debts($book);
        $this->audit($book, $from, $to);
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'fin') . '.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    public static function filename(CarbonInterface $from, CarbonInterface $to): string
    {
        return 'Nuviora - Finanzas ' . $from->format('Y-m-d') . ($from->equalTo($to) ? '' : ' a ' . $to->format('Y-m-d')) . '.xlsx';
    }

    private function summary(Spreadsheet $book, CarbonInterface $from, CarbonInterface $to): void
    {
        $now = $this->pnl->report($from, $to);
        [$pf, $pt] = ProfitAndLoss::previousPeriod($from, $to);
        $prev = $this->pnl->report($pf, $pt);
        $rows = [
            ['Período', "{$from->format('d/m/Y')} al {$to->format('d/m/Y')}", "{$pf->format('d/m/Y')} al {$pt->format('d/m/Y')}", ''],
            ['Estado (§20)', $now['status']['label'], $prev['status']['label'], ''],
            ['Concepto (§47)', 'Este período', 'Período anterior', 'Diferencia'],
        ];
        foreach (self::LEVELS as $key => $label) {
            $rows[] = [$label, $now['company'][$key] ?? 0, $prev['company'][$key] ?? 0, round(($now['company'][$key] ?? 0) - ($prev['company'][$key] ?? 0), 2)];
        }
        $rows[] = ['Margen de contribución %', $now['company']['margin_pct'], $prev['company']['margin_pct'], null];
        $rows[] = ['Órdenes entregadas', $now['company']['orders_delivered'], $prev['company']['orders_delivered'], null];
        $rows[] = ['Órdenes no entregadas con costo', $now['company']['orders_not_delivered'], $prev['company']['orders_not_delivered'], null];
        $rows[] = ['Beneficio de upsells', $now['company']['upsell']['profit'], $prev['company']['upsell']['profit'], null];
        $rows[] = ['', '', '', ''];
        foreach (array_merge($now['status']['reasons'], $now['warnings']) as $w) {
            $rows[] = [$w, '', '', ''];
        }
        $sheet = $this->sheet($book, 'Resumen', $rows, 3);
        $sheet->getStyle('B4:D' . (3 + count(self::LEVELS) + 4))->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getColumnDimension('A')->setWidth(42);
    }

    private function groups(Spreadsheet $book, string $title, array $groups): void
    {
        $head = ['Nombre', 'Entregadas', 'No entregadas con costo'];
        foreach (self::LEVELS as $label) {
            $head[] = trim($label);
        }
        $head[] = 'Margen %';
        $rows = [$head];
        foreach ($groups as $g) {
            $row = [$g['name'], $g['orders_delivered'], $g['orders_not_delivered']];
            foreach (array_keys(self::LEVELS) as $key) {
                $row[] = $g[$key] ?? null;
            }
            $row[] = $g['margin_pct'];
            $rows[] = $row;
        }
        $sheet = $this->sheet($book, $title, $rows);
        $sheet->getStyle('D2:Q' . max(2, count($rows)))->getNumberFormat()->setFormatCode(self::MONEY);
    }

    /** §49: cada orden del período (por su fecha de costo, §3 y §5) con todo lo que la compone. */
    private function orders(Spreadsheet $book, CarbonInterface $from, CarbonInterface $to): void
    {
        $rows = [[
            'Orden', 'Tipo', 'Creada', 'Entregada', 'Fecha de costo', 'Resultado', 'Producto', 'Ciudad', 'Agencia', 'Ingreso principal', 'Ingreso upsells',
            'Reembolsado', 'Ingreso neto', 'COGS principal', 'COGS upsells', 'Origen del costo', 'Logística', 'Delivery', 'Delivery histórico (incluido)',
            'Comisión vendedora', 'Comisión upsells', 'Comisión líder', 'Repartidor propio', 'Contribución antes de publicidad', 'Líneas sin costo', 'Carreras sin costo',
        ]];
        DB::table('order_financials as f')->join('orders as o', 'o.id', '=', 'f.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'f.product_id')->leftJoin('cities as c', 'c.id', '=', 'f.city_id')->leftJoin('users as a', 'a.id', '=', 'f.agency_id')
            ->whereBetween('f.cost_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('f.cost_date')->orderBy('f.order_id')
            ->select('f.*', 'o.name as order_name', 'p.title as product', 'c.name as city', 'a.names as agency')
            ->chunk(2000, function ($chunk) use (&$rows) {
                foreach ($chunk as $f) {
                    $net = $f->revenue_main + $f->revenue_upsell - $f->revenue_refunded;
                    $cost = $f->cogs_main + $f->cogs_upsell + $f->logistics_cost + $f->delivery_cost + $f->commission_seller + $f->commission_upsell
                        + $f->commission_leader + $f->commission_deliverer;
                    $rows[] = [
                        $f->order_name, ['normal' => 'Normal', 'exchange' => 'Cambio', 'return' => 'Devolución'][$f->kind] ?? $f->kind, $f->created_date, $f->delivered_date,
                        $f->cost_date, ['delivered' => 'Entregada', 'not_delivered' => 'No entregada', 'open' => 'Abierta'][$f->outcome] ?? $f->outcome,
                        $f->product, $f->city, $f->agency, (float) $f->revenue_main, (float) $f->revenue_upsell, (float) $f->revenue_refunded, round($net, 2),
                        (float) $f->cogs_main, (float) $f->cogs_upsell, ['lots' => 'Lotes', 'catalog' => 'Catálogo', 'mixed' => 'Mixto'][$f->cogs_source ?? ''] ?? ($f->cogs_source ?? ''),
                        (float) $f->logistics_cost, (float) $f->delivery_cost, (float) $f->delivery_legacy_cost, (float) $f->commission_seller,
                        (float) $f->commission_upsell, (float) $f->commission_leader, (float) $f->commission_deliverer, round($net - $cost, 2),
                        (int) $f->cogs_missing_lines, (int) $f->delivery_missing_trips,
                    ];
                }
            });
        $sheet = $this->sheet($book, 'Órdenes', $rows);
        $last = max(2, count($rows));
        $sheet->getStyle("J2:O{$last}")->getNumberFormat()->setFormatCode(self::MONEY);
        $sheet->getStyle("Q2:X{$last}")->getNumberFormat()->setFormatCode(self::MONEY);
    }

    /** §24 a §28: los gastos manuales del período, lo que los recurrentes reparten en él y sus pagos. */
    private function expensesSheet(Spreadsheet $book, CarbonInterface $from, CarbonInterface $to): void
    {
        $rows = [['Tipo', 'Fecha', 'Concepto', 'Categoría', 'Producto o ciudad', 'Monto', 'Moneda', 'Tasa', 'USD', 'Cuenta de pago', 'Estado', 'Motivo de anulación']];
        foreach (Expense::with(['category:id,name', 'product:id,title', 'city:id,name', 'account:id,name'])
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])->orderBy('expense_date')->get() as $e) {
            $rows[] = ['Gasto', $e->expense_date->toDateString(), $e->concept, $e->category?->name, trim(($e->product?->title ?? '') . ' ' . ($e->city?->name ?? '')),
                (float) $e->amount, $e->currency, (float) $e->rate, (float) $e->amount_usd, $e->account?->name ?? ($e->payable ? 'Por pagar' : ''),
                $e->voided_at ? 'Anulado' : 'Vigente', $e->void_reason];
        }
        foreach ($this->expenses->forRange($from, $to)['items'] as $i) {
            if ($i['type'] === 'recurring') {
                $rows[] = ['Recurrente (repartido en el período, §28)', '', $i['name'], $i['category'], '', null, 'USD', null, $i['amount_usd'], '', 'Vigente', ''];
            }
        }
        foreach (RecurringExpensePayment::with(['recurring:id,name', 'account:id,name'])->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->orderBy('paid_at')->get() as $p) {
            $rows[] = ['Pago de recurrente (caja)', $p->paid_at->toDateString(), ($p->recurring?->name ?? '') . ' (vence ' . $p->due_date->format('d/m') . ')', '', '',
                $p->amount, $p->currency, $p->rate, $p->amount_usd, $p->account?->name, $p->voided_at ? 'Anulado' : 'Vigente', $p->void_reason];
        }
        $sheet = $this->sheet($book, 'Gastos', $rows);
        $sheet->getStyle('F2:I' . max(2, count($rows)))->getNumberFormat()->setFormatCode(self::MONEY);
    }

    /** §34: los movimientos de caja del período, también los anulados (§83). */
    private function cash(Spreadsheet $book, CarbonInterface $from, CarbonInterface $to): void
    {
        $rows = [['Fecha', 'Cuenta', 'Tipo', 'Descripción', 'Monto', 'Moneda', 'Tasa', 'USD', 'Estado', 'Orden', 'Anulado', 'Motivo']];
        Tx::with(['account:id,name', 'order:id,name'])->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('occurred_on')->orderBy('id')->chunk(2000, function ($chunk) use (&$rows) {
                foreach ($chunk as $t) {
                    $rows[] = [$t->occurred_on->toDateString(), $t->account?->name, Tx::KIND_LABEL[$t->kind] ?? $t->kind, $t->description, (float) $t->amount,
                        $t->currency, (float) $t->rate, (float) $t->amount_usd, Tx::STATUS_LABEL[$t->status] ?? $t->status, $t->order?->name,
                        $t->voided_at?->format('Y-m-d H:i'), $t->void_reason];
                }
            });
        $rows[] = [];
        $rows[] = ['Saldos al exportar', '', '', '', '', '', '', '', '', '', '', ''];
        foreach ($this->ledger->balances() as $a) {
            $rows[] = ['', $a['name'], $a['kind_label'], 'Saldo inicial ' . ($a['opening_balance'] ?? 'sin poner') . ' al ' . ($a['opening_date'] ?? '—'),
                (float) $a['balance'], $a['currency'], '', CashLedger::toUsdNow((float) $a['balance'], $a['currency']), $a['is_active'] ? 'Activa' : 'Inactiva', '', '', ''];
        }
        $sheet = $this->sheet($book, 'Caja', $rows);
        $sheet->getStyle('E2:H' . count($rows))->getNumberFormat()->setFormatCode(self::MONEY);
    }

    /** §44 y §45: lo que se debe y lo que se cobra, con saldo, al exportar. */
    private function debts(Spreadsheet $book): void
    {
        $rows = [['', 'A quién / quién debe', 'Qué', 'Emitida', 'Vence', 'Moneda', 'Total', 'Pagado / cobrado', 'Pendiente', 'Pendiente USD', 'Estado']];
        foreach (AccountPayable::with(['supplier:id,name', 'agency:id,names'])->whereNull('voided_at')->orderBy('due_date')->get() as $p) {
            if ($p->balance() > 0.005) {
                $rows[] = ['Por pagar', $p->payeeName(), $p->description, $p->issued_at?->toDateString(), $p->due_date?->toDateString(), $p->currency, $p->amount,
                    $p->paid_amount, $p->balance(), $p->balanceUsd(), $p->status() === 'overdue' ? 'Vencida' : 'Pendiente'];
            }
        }
        foreach (AccountReceivable::whereNull('voided_at')->orderBy('due_date')->get() as $r) {
            if ($r->balance() > 0.005) {
                $rows[] = ['Por cobrar', $r->debtor_name, $r->description, $r->issued_at?->toDateString(), $r->due_date?->toDateString(), $r->currency, $r->amount,
                    $r->collected_amount, $r->balance(), $r->balanceUsd(), $r->status() === 'overdue' ? 'Vencida' : 'Pendiente'];
            }
        }
        $sheet = $this->sheet($book, 'Por pagar y cobrar', $rows);
        $sheet->getStyle('G2:J' . max(2, count($rows)))->getNumberFormat()->setFormatCode(self::MONEY);
    }

    /** §82: quién cambió qué, cuándo y por qué. */
    private function audit(Spreadsheet $book, CarbonInterface $from, CarbonInterface $to): void
    {
        $rows = [['Fecha', 'Usuario', 'Acción', 'Qué', 'Id', 'Motivo', 'Antes', 'Después']];
        FinancialAuditLog::with('user:id,names')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('id')
            ->chunk(2000, function ($chunk) use (&$rows) {
                foreach ($chunk as $l) {
                    $rows[] = [$l->created_at?->format('Y-m-d H:i'), $l->user?->names, $l->action, $l->subject_type, $l->subject_id, $l->reason,
                        mb_substr(json_encode($l->before, JSON_UNESCAPED_UNICODE) ?: '', 0, 30000), mb_substr(json_encode($l->after, JSON_UNESCAPED_UNICODE) ?: '', 0, 30000)];
                }
            });
        $this->sheet($book, 'Auditoría', $rows);
    }

    private function sheet(Spreadsheet $book, string $title, array $rows, int $headerRow = 1): Worksheet
    {
        $sheet = $book->createSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));
        $sheet->fromArray($rows, null, 'A1', true);
        $lastCol = $sheet->getHighestColumn();
        $sheet->getStyle("A{$headerRow}:{$lastCol}{$headerRow}")->getFont()->setBold(true);
        $sheet->freezePane('A' . ($headerRow + 1));
        // Con muchas filas, medir cada celda tarda demasiado: ancho fijo
        foreach (range(1, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastCol)) as $i) {
            count($rows) > 3000 ? $sheet->getColumnDimensionByColumn($i)->setWidth(16) : $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }

        return $sheet;
    }
}
