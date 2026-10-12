<?php

use App\Models\AgencyClosureSetting;
use App\Models\AgencyWeeklyClosure;
use App\Services\Delivery\Closures\ClosureFileReader;
use App\Services\Delivery\Closures\ClosureNormalizer;
use Illuminate\Support\Carbon;

// Módulo 3 de Fran, fase 4 (cierres semanales): normalización (§26), tolerancia (§30), el aviso (§21, §22) y la lectura
// del archivo de la agencia (§24, §25), sin base de datos ni Internet. Los casos con carreras están en
// infra/tarifas-delivery/qa-cierres.php.

describe('Normalización antes de emparejar (§26)', function () {
    it('el número de orden con el ruido de PYP', function () {
        expect(ClosureNormalizer::orderKey('###54563'))->toBe(['key' => '54563', 'repeat' => false]);
        expect(ClosureNormalizer::orderKey('##4617'))->toBe(['key' => '4617', 'repeat' => false]);
        expect(ClosureNormalizer::orderKey('#MAN-27114'))->toBe(['key' => 'MAN-27114', 'repeat' => false]);
        expect(ClosureNormalizer::orderKey(' 54566 '))->toBe(['key' => '54566', 'repeat' => false]);
        expect(ClosureNormalizer::orderKey('#4747 (1)'))->toBe(['key' => '4747', 'repeat' => true]);
        expect(ClosureNormalizer::orderKey('man 338'))->toBe(['key' => 'MAN-338', 'repeat' => false]);
        expect(ClosureNormalizer::orderKey('efectivo')['key'])->toBeNull();
    });

    it('la orden del sistema se reconoce por su nombre y su número', function () {
        expect(ClosureNormalizer::orderKeysFor('#54340', 54340))->toBe(['54340']);
        expect(ClosureNormalizer::orderKeysFor('MAN-338', 338))->toBe(['MAN-338', '338']);
    });

    it('teléfonos venezolanos en cualquier forma', function () {
        foreach (['0414-555.12.34', '(0414) 5551234', '+58 414 5551234', '584145551234', '4145551234', '+58 0414 555 1234'] as $raw) {
            expect(ClosureNormalizer::phone($raw))->toBe('04145551234');
        }
        expect(ClosureNormalizer::phone('(0212) 555-1234'))->toBe('02125551234');
        expect(ClosureNormalizer::phone('123'))->toBeNull();
        expect(ClosureNormalizer::phoneIn('La Quinta/ terminal de maracay 4243609501'))->toBe('04243609501');
    });

    it('nombres sin mayúsculas, tildes, signos ni espacios dobles', function () {
        expect(ClosureNormalizer::name('  MARÍA   José. '))->toBe('maria jose');
        expect(ClosureNormalizer::name('Jamil -'))->toBe('jamil');
        expect(ClosureNormalizer::name('—'))->toBeNull();
    });

    it('cuánto se parece un nombre: la primera palabra manda, y se admite una letra de diferencia', function () {
        expect(ClosureNormalizer::nameScore('carlos', 'carlos ruiz'))->toBe(1.0);
        expect(ClosureNormalizer::nameScore('jaun sierra', 'juan sierra'))->toBe(1.0);
        expect(ClosureNormalizer::nameScore('miguel gonzales', 'miguel gonzalez'))->toBe(1.0);
        expect(ClosureNormalizer::nameScore('maria perez', 'maria rodriguez'))->toBe(0.5);
        expect(ClosureNormalizer::nameScore('ruiz carlos', 'carlos mendoza'))->toBe(0.0);
        expect(ClosureNormalizer::nameScore('efectivo', 'eliener finol'))->toBe(0.0);
    });

    it('fechas como las escriben las agencias (día primero)', function () {
        expect(ClosureNormalizer::date('28-sept-2026'))->toBe('2026-09-28');
        expect(ClosureNormalizer::date('1/10/26'))->toBe('2026-10-01');
        expect(ClosureNormalizer::date('29/9/26'))->toBe('2026-09-29');
        expect(ClosureNormalizer::date('2026-10-03 00:00'))->toBe('2026-10-03');
        expect(ClosureNormalizer::date('3 de octubre de 2026'))->toBe('2026-10-03');
        expect(ClosureNormalizer::date('31/02/26'))->toBeNull();
    });
});

describe('Tolerancia (§29, §30)', function () {
    it('sin tolerancia, solo cuadra lo exacto (casos 11 y 12)', function () {
        $s = new AgencyClosureSetting();
        expect($s->withinTolerance(4, 4))->toBeTrue();
        expect($s->withinTolerance(4, 5))->toBeFalse();
        expect($s->withinTolerance(4, 4.01))->toBeFalse();
    });

    it('el ejemplo del §30: $0,25 → $4,10 cuadra y $5 no', function () {
        $s = new AgencyClosureSetting(['tolerance_amount' => 0.25]);
        expect($s->withinTolerance(4, 4.10))->toBeTrue();
        expect($s->withinTolerance(4, 4.25))->toBeTrue();
        expect($s->withinTolerance(4, 3.75))->toBeTrue();
        expect($s->withinTolerance(4, 5))->toBeFalse();
    });

    it('con porcentaje, vale el mayor de los dos', function () {
        $s = new AgencyClosureSetting(['tolerance_amount' => 0.10, 'tolerance_percent' => 10]);
        expect($s->withinTolerance(4, 4.40))->toBeTrue();
        expect($s->withinTolerance(4, 4.41))->toBeFalse();
        expect($s->withinTolerance(0.5, 0.60))->toBeTrue();
    });
});

describe('Semana y aviso (§20 a §22)', function () {
    $closure = fn (string $status = AgencyWeeklyClosure::PENDING_FILE) => new AgencyWeeklyClosure(['period_start' => '2026-10-05', 'period_end' => '2026-10-11', 'status' => $status]);
    $at = fn (string $when) => Carbon::parse($when, config('app.timezone'));

    it('la semana se nombra como en el documento', function () use ($closure) {
        expect($closure()->weekLabel())->toBe('5–11 octubre');
        expect((new AgencyWeeklyClosure(['period_start' => '2026-09-28', 'period_end' => '2026-10-04']))->weekLabel())->toBe('28 septiembre – 4 octubre');
    });

    it('por defecto, el miércoles siguiente a las 11:00 (Módulo 4, R15)', function () use ($closure, $at) {
        $s = new AgencyClosureSetting();
        expect($closure()->reminderAt($s)->format('Y-m-d H:i'))->toBe('2026-10-14 11:00');
        expect($closure()->reminderDue($s, $at('2026-10-14 10:59')))->toBeFalse();
        expect($closure()->reminderDue($s, $at('2026-10-14 11:00')))->toBeTrue();
        expect($closure()->reminderDue($s, $at('2026-10-20 12:00')))->toBeTrue();
    });

    it('desaparece cuando ya hay archivo, y se puede desactivar', function () use ($closure, $at) {
        expect($closure(AgencyWeeklyClosure::FILE_RECEIVED)->reminderDue(new AgencyClosureSetting(), $at('2026-10-15')))->toBeFalse();
        expect($closure()->reminderDue(new AgencyClosureSetting(['reminder_active' => false]), $at('2026-10-15')))->toBeFalse();
    });

    it('día y hora configurables (§21)', function () use ($closure) {
        $s = new AgencyClosureSetting(['closing_day' => 1, 'closing_time' => '18:30:00']);
        expect($closure()->reminderAt($s)->format('Y-m-d H:i'))->toBe('2026-10-12 18:30');
    });
});

describe('El archivo de la agencia (§24, §25)', function () {
    $csv = function (array $lines): string {
        $path = tempnam(sys_get_temp_dir(), 'cierre') . '.csv';
        file_put_contents($path, implode("\n", array_map(fn ($l) => implode(';', $l), $lines)));
        return $path;
    };

    it('reconoce las columnas por sus títulos y lee cada fila con código', function () use ($csv) {
        $path = $csv([
            ['Relación de servicios', '', '', '', ''],
            ['orden', 'puntociclista', 'cliente', 'costo', 'fecha'],
            ['##4617', 'Luigis Diaz', 'Nelson', '3', '29/09/2026'],
            ['#4747 (1)', 'Pedro', 'Juan', '4,30', '30/09/2026'],
            ['', '', '', '7,30', ''],
        ]);
        $read = app(ClosureFileReader::class)->read($path, 'csv', new AgencyClosureSetting());
        expect($read['mapping']['header_row'])->toBe(2);
        expect($read['mapping']['columns'])->toBe(['orden' => 'A', 'cliente' => 'C', 'monto' => 'D', 'fecha' => 'E']);
        expect($read['rows'])->toHaveCount(2);
        expect($read['rows'][1])->toMatchArray(['orden' => '#4747 (1)', 'cliente' => 'Juan', 'monto' => 4.3, 'fecha' => '2026-09-30', 'position' => 4]);
        expect($read['rows'][1]['raw']['celdas']['B'])->toBe('Pedro');
        unlink($path);
    });

    it('un mapeo puesto a mano vale aunque los títulos no se reconozcan', function () use ($csv) {
        $path = $csv([['a', 'b', 'c'], ['01/10/26', 'Ana', '3,50']]);
        $setting = new AgencyClosureSetting(['file_mapping' => ['header_row' => 1, 'columns' => ['fecha' => 'A', 'cliente' => 'B', 'monto' => 'C'], 'manual' => true]]);
        $read = app(ClosureFileReader::class)->read($path, 'csv', $setting);
        expect($read['rows'][0])->toMatchArray(['cliente' => 'Ana', 'monto' => 3.5, 'fecha' => '2026-10-01']);
        unlink($path);
    });

    it('sin títulos reconocibles ni IA, avisa en vez de adivinar', function () use ($csv) {
        config(['services.openai.key' => null]);
        $path = $csv([['a', 'b'], ['x', 'y']]);
        expect(fn () => app(ClosureFileReader::class)->read($path, 'csv', new AgencyClosureSetting()))
            ->toThrow(RuntimeException::class, 'No se reconocieron las columnas');
        unlink($path);
    });

    it('el Excel real de PYP del 28-09: 185 carreras y $513,70', function () {
        $file = dirname(base_path()) . '/docs/modulo3-ejemplos-agencias/PYP (Caracas) 28-09 al 03-10.xlsx';
        if (!class_exists(ZipArchive::class) || !is_file($file)) {
            $this->markTestSkipped('Hace falta la extensión zip y el ejemplo de PYP.');
        }
        $rows = app(ClosureFileReader::class)->read($file, 'xlsx', new AgencyClosureSetting())['rows'];
        expect($rows)->toHaveCount(185);
        expect(round(array_sum(array_column($rows, 'monto')), 2))->toBe(513.7);
    });
});
