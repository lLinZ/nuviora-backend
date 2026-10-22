<?php

namespace App\Services\Logistics;

use App\Constants\LogisticsStatus;
use App\Models\LogisticsCenter;
use App\Models\LogisticsNovelty;
use App\Models\LogisticsOrder;

/**
 * La torre de control del operador (logística propia, §21): las órdenes del centro con su reloj, lo que le toca hacer
 * con cada una, la cola de prioridad (§21.3) y el resumen de arriba (§21.1). Lo que el documento pide y todavía no
 * existe (incidencias graves, caja del rider, retornos con plazo) no entra en la cola ni en el resumen hasta que se haga.
 */
class Tower
{
    /**
     * §21.3: "ruta interrumpida > SLA crítico > novedad con rider esperando > efectivo sobre límite > pedido sin rider
     * cerca del minuto 5 > retorno vencido > tareas normales de picking". Los puestos 1, 4 y 6 llegan con las incidencias
     * (§14), la caja (§17) y los retornos (§16); por eso faltan. "Buscando rider" antes de la alerta del minuto 3 va al
     * final: las rondas corren solas y el operador todavía no tiene que hacer nada.
     */
    public const PRIORITY = [
        'sla_vencido' => [2, 'SLA vencido'],
        'novedad' => [3, 'Novedad: el rider espera'],
        'sin_rider' => [5, 'Sin rider: llamar y asignar a mano'],
        'preparar' => [7, 'Preparar el paquete'],
        'marcar_listo' => [7, 'Marcar listo para despacho'],
        'confirmar_salida' => [7, 'Confirmar salida cuando llegue el rider'],
        'entregar_agencia' => [7, 'Entregar el paquete a la agencia'],
        'buscando' => [8, 'Buscando rider (rondas en curso)'],
    ];

    public function __construct(private LogisticsSettings $settings)
    {
    }

    /** Las órdenes del centro que siguen en curso (o todas), ya ordenadas por la cola. */
    public function orders(LogisticsCenter $center, bool $all = false): array
    {
        $settings = $this->settings->for($center);
        $rows = LogisticsOrder::with([
            'order:id,name,order_number,current_total_price,client_id,status_id,location',
            'order.client:id,first_name,last_name,phone,address1',
            'order.status:id,description',
            'rider:id,names,phone',
            'agency:id,names',
            'rounds',
            'custody',
            'novelties' => fn ($q) => $q->whereNull('resolved_at')->with('reporter:id,names'),
        ])
            ->where('center_id', $center->id)
            ->when(!$all, fn ($q) => $q->whereNotIn('status', LogisticsStatus::FINISHED))
            ->orderBy('id')->limit(500)->get();

        $out = $rows->map(fn (LogisticsOrder $r) => $this->row($r, $settings))->all();
        usort($out, fn ($a, $b) => [$a['priority'] ?? 99, -($a['sla_minutes'] ?? 0), $a['id']] <=> [$b['priority'] ?? 99, -($b['sla_minutes'] ?? 0), $b['id']]);

        return $out;
    }

    /** §21.1, con lo que ya existe. */
    public function summary(array $rows): array
    {
        $count = fn (array $statuses) => count(array_filter($rows, fn ($r) => in_array($r['status'], $statuses, true)));

        return [
            'sin_rider' => $count(LogisticsStatus::UNASSIGNED),
            'preparando_listos' => $count([LogisticsStatus::RIDER_CONFIRMED, LogisticsStatus::PREPARING, LogisticsStatus::READY]),
            'rutas_activas' => count(array_unique(array_filter(array_map(
                fn ($r) => in_array($r['status'], [LogisticsStatus::EN_ROUTE, LogisticsStatus::INCIDENT], true) ? $r['rider_id'] : null, $rows)))),
            'en_agencia' => $count([LogisticsStatus::AGENCY]),
            'sla_riesgo' => count(array_filter($rows, fn ($r) => in_array($r['sla_level'], ['riesgo', 'vencido'], true))),
            'novedades' => count(array_filter($rows, fn ($r) => $r['open_novelty'] !== null)),
            'retornos' => $count([LogisticsStatus::RETURN_PENDING]),
        ];
    }

    public function row(LogisticsOrder $r, array $settings): array
    {
        $sla = $r->slaMinutes();
        $searching = $r->searching_started_at && $r->status === LogisticsStatus::SEARCHING
            ? (int) $r->searching_started_at->diffInSeconds(now()) : null;
        $round = $r->rounds->sortByDesc('number')->first();
        $novelty = $r->novelties->sortByDesc('id')->first();
        $handed = $r->custody->contains(fn ($c) => $c->to_type === 'agencia');
        $key = $this->priorityKey($r, $sla, $searching, $novelty, $handed, $settings);

        return $r->toArray() + [
            'status_label' => LogisticsStatus::label($r->status),
            'sla_minutes' => $sla,
            'sla_level' => self::slaLevel($sla, $settings),
            'searching_seconds' => $searching,
            'calls_in_seconds' => $searching !== null ? max(0, (int) $settings['manual_calls_at_seconds'] - $searching) : null,
            'agency_in_seconds' => $searching !== null ? max(0, (int) $settings['hard_cap_seconds'] - $searching) : null,
            'round' => $round && !$round->ended_at ? ['number' => $round->number, 'expires_at' => $round->expires_at] : null,
            'ready' => $r->ready_at !== null,
            'handed_to_agency' => $handed,
            'open_novelty' => $novelty ? [
                'id' => $novelty->id,
                'type' => $novelty->type,
                'type_label' => $novelty->typeLabel(),
                'commercial' => (bool) (LogisticsNovelty::TYPES[$novelty->type]['escalar'] ?? false),
                'description' => $novelty->description,
                'owner' => $novelty->owner,
                'reporter' => $novelty->reporter?->names,
                'opened_at' => $novelty->opened_at,
                'escalated_at' => $novelty->escalated_at,
                'comments' => $novelty->comments ?? [],
            ] : null,
            'priority' => $key ? self::PRIORITY[$key][0] : null,
            'priority_key' => $key,
            'priority_label' => $key ? self::PRIORITY[$key][1] : null,
        ];
    }

    public static function slaLevel(?float $minutes, array $settings): ?string
    {
        if ($minutes === null) {
            return null;
        }

        return match (true) {
            $minutes >= $settings['sla_limit_minutes'] => 'vencido',
            $minutes >= $settings['sla_target_minutes'] => 'riesgo',
            default => 'ok',
        };
    }

    private function priorityKey(LogisticsOrder $r, ?float $sla, ?int $searching, ?LogisticsNovelty $novelty, bool $handed, array $settings): ?string
    {
        $open = !in_array($r->status, [...LogisticsStatus::FINISHED, LogisticsStatus::PENDING_WINDOW], true);
        if ($open && self::slaLevel($sla, $settings) === 'vencido') {
            return 'sla_vencido';
        }
        // La novedad escalada ya está con la vendedora: el operador solo espera
        if ($novelty && $novelty->owner === 'operador' && !$novelty->escalated_at) {
            return 'novedad';
        }

        return match (true) {
            $r->status === LogisticsStatus::SEARCHING && $searching !== null && $searching >= (int) $settings['manual_calls_at_seconds'] => 'sin_rider',
            $r->status === LogisticsStatus::RIDER_CONFIRMED => 'preparar',
            $r->status === LogisticsStatus::PREPARING => 'marcar_listo',
            $r->status === LogisticsStatus::READY => 'confirmar_salida',
            $r->status === LogisticsStatus::AGENCY && !$handed => 'entregar_agencia',
            $r->status === LogisticsStatus::SEARCHING => 'buscando',
            default => null,
        };
    }
}
