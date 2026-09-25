<?php

use App\Services\Assignment\Weighted\EffectiveWeights;
use App\Services\Assignment\Weighted\SmoothWeightedRoundRobin;

/** Corre $n turnos y devuelve la secuencia de elegidos y el saldo final. */
function runTurns(array $weights, int $n, array $current = []): array
{
    $sequence = [];
    for ($i = 0; $i < $n; $i++) {
        [$picked, $current] = SmoothWeightedRoundRobin::pick($weights, $current);
        $sequence[] = $picked;
    }

    return [$sequence, $current];
}

function longestRun(array $sequence, int $id): int
{
    $best = $run = 0;
    foreach ($sequence as $picked) {
        $run = $picked === $id ? $run + 1 : 0;
        $best = max($best, $run);
    }

    return $best;
}

describe('Smooth Weighted Round Robin', function () {
    it('reparte 70/30 intercalado y sin bloques de siete seguidas', function () {
        [$sequence, $current] = runTurns([1 => 70, 2 => 30], 10);

        expect(array_count_values($sequence))->toBe([1 => 7, 2 => 3])
            ->and(longestRun($sequence, 1))->toBeLessThanOrEqual(3)
            ->and(array_sum(array_map('abs', $current)))->toBeLessThan(1e-6); // el ciclo cierra en cero
    });

    it('respeta 50/25/25 con cualquier cantidad de órdenes', function () {
        foreach ([3, 7, 23, 150] as $n) {
            [$sequence] = runTurns([1 => 50, 2 => 25, 3 => 25], $n);
            $counts = array_count_values($sequence) + [1 => 0, 2 => 0, 3 => 0];
            expect(abs($counts[1] - $n * 0.5))->toBeLessThanOrEqual(1)
                ->and(abs($counts[2] - $n * 0.25))->toBeLessThanOrEqual(1)
                ->and(abs($counts[3] - $n * 0.25))->toBeLessThanOrEqual(1);
        }
    });

    it('sin pesos distintos hace la rotación de siempre', function () {
        [$sequence] = runTurns([1 => 1, 2 => 1, 3 => 1], 6);

        expect($sequence)->toBe([1, 2, 3, 1, 2, 3]);
    });

    it('quien vuelve después de estar fuera no recibe una ráfaga', function () {
        [, $current] = runTurns([1 => 1, 2 => 1], 10);
        [$whileOut, $current] = runTurns([2 => 1], 10, $current); // 1 llegó a su máximo
        [$afterReturn] = runTurns([1 => 1, 2 => 1], 10, $current);

        expect(array_unique($whileOut))->toBe([2])
            ->and(array_count_values($afterReturn)[1])->toBe(5)
            ->and(longestRun($afterReturn, 1))->toBe(1);
    });

    it('ignora pesos en cero y exige al menos un participante', function () {
        [$sequence] = runTurns([1 => 0, 2 => 5], 3);
        expect($sequence)->toBe([2, 2, 2]);

        SmoothWeightedRoundRobin::pick([1 => 0], []);
    })->throws(InvalidArgumentException::class);
});

describe('Pesos efectivos por grupos', function () {
    $seller = fn (?int $group, ?float $weight = null) => ['group' => $group, 'leader' => false, 'weight' => $weight];
    $leader = fn (int $group) => ['group' => $group, 'leader' => true, 'weight' => 99.0];

    it('sin grupos todas valen lo mismo', function () use ($seller) {
        expect(EffectiveWeights::compute([5 => $seller(null), 9 => $seller(null)], []))->toBe([5 => 1.0, 9 => 1.0]);
    });

    it('un grupo de 7 y uno de 3 dan 70/30, y la Líder reparte dentro del suyo', function () use ($seller) {
        $candidates = [1 => $seller(10, 40)];
        foreach (range(2, 7) as $id) {
            $candidates[$id] = $seller(10, 10);
        }
        foreach (range(8, 10) as $id) {
            $candidates[$id] = $seller(20);
        }

        $weights = EffectiveWeights::compute($candidates, []);
        $total = array_sum($weights);

        expect(round(array_sum(array_intersect_key($weights, array_flip(range(1, 7)))) / $total, 4))->toBe(0.7)
            ->and(round($weights[1] / $total, 4))->toBe(0.28)
            ->and(round($weights[2] / $total, 4))->toBe(0.07)
            ->and(round($weights[8] / $total, 4))->toBe(0.1);
    });

    it('cuenta solo las disponibles: si falta una del grupo de 3, las otras dos no cargan el 30 %', function () use ($seller) {
        $candidates = [];
        foreach (range(1, 7) as $id) {
            $candidates[$id] = $seller(10);
        }
        $candidates[8] = $seller(20);
        $candidates[9] = $seller(20); // la 10 no vino hoy

        $weights = EffectiveWeights::compute($candidates, []);

        expect(round(($weights[8] + $weights[9]) / array_sum($weights), 4))->toBe(round(2 / 9, 4));
    });

    it('la Líder recibe su carga y no la que ella misma se ponga', function () use ($seller, $leader) {
        $weights = EffectiveWeights::compute([1 => $leader(10), 2 => $seller(10), 3 => $seller(10)], [10 => 0.65]);

        expect($weights)->toBe([1 => 0.65, 2 => 1.0, 3 => 1.0]);
    });

    it('una Líder con carga 0 no vende', function () use ($seller, $leader) {
        expect(EffectiveWeights::compute([1 => $leader(10), 2 => $seller(10)], [10 => 0]))->toBe([2 => 1.0]);
    });

    it('una vendedora recién agregada, sin %, recibe como el promedio del grupo', function () use ($seller) {
        $weights = EffectiveWeights::compute([1 => $seller(10, 60), 2 => $seller(10, 20), 3 => $seller(10)], []);

        // 60, 20 y 40 (el promedio) suman 120: la 1 recibe 3 × 60 / 120 = 1,5 porciones
        expect(round($weights[3], 4))->toBe(1.0)
            ->and(round($weights[1], 4))->toBe(1.5);
    });

    it('si la Líder pone a todas en 0 %, su grupo no recibe', function () use ($seller) {
        expect(EffectiveWeights::compute([1 => $seller(10, 0), 2 => $seller(10, 0), 3 => $seller(null)], []))->toBe([3 => 1.0]);
    });

    it('junto con el motor, cumple los porcentajes en 1000 órdenes', function () use ($seller) {
        $candidates = [1 => $seller(10, 40)];
        foreach (range(2, 7) as $id) {
            $candidates[$id] = $seller(10, 10);
        }
        foreach (range(8, 10) as $id) {
            $candidates[$id] = $seller(20);
        }
        [$sequence] = runTurns(EffectiveWeights::compute($candidates, []), 1000);
        $counts = array_count_values($sequence);

        expect($counts[1])->toBe(280)
            ->and($counts[2])->toBe(70)
            ->and($counts[8])->toBe(100);
    });
});
