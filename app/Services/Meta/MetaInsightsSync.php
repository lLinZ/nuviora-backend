<?php

namespace App\Services\Meta;

use App\Models\MetaAd;
use App\Models\MetaAdAccount;
use App\Models\MetaAdset;
use App\Models\MetaCampaign;
use App\Models\MetaInsightCurrent;
use App\Models\MetaInsightDaily;
use Illuminate\Support\Carbon;

/**
 * Las métricas de una cuenta (documento de Fran del 2026-10-08, Módulo 2):
 * - a nivel campaña, ad set y anuncio (§14), con los valores base (§15, §20, §50);
 * - las compras con la atribución unificada del ad set, como en el Administrador de anuncios (§16);
 * - el histórico, una fila por día, que se reemplaza al volver a pedir ese día (§24, §25);
 * - el estado actual de los rangos fijos, con el alcance y la frecuencia de Meta (§27, §57).
 * Una llamada por cuenta, nivel y rango, con paginación (§52). Si Meta no acepta un campo, se pide sin él; si pide
 * menos datos, se parte el rango.
 */
class MetaInsightsSync
{
    public const LEVELS = ['campaign', 'adset', 'ad'];

    /** Rangos fijos del §27, con su nombre en Meta. Hoy y ayer salen del histórico diario. */
    public const PRESETS = ['last_3d', 'last_7d', 'last_14d', 'last_30d', 'this_week_mon_today', 'last_week_mon_sun', 'this_month', 'last_month'];

    /** Campos que Meta no aceptó en esta corrida (§54: "campo no disponible"). */
    private array $dropped = [];

    public function dropped(): array
    {
        return array_keys($this->dropped);
    }

    /** @return int filas guardadas */
    public function daily(MetaAdAccount $account, MetaClient $client, string $level, string $since, string $until): int
    {
        $rows = $this->fetchRange($client, $account, $level, $since, $until);
        $now = now();
        foreach ($rows as $row) {
            $metaId = self::entityId($row, $level);
            if (!$metaId || empty($row['date_start'])) {
                continue;
            }
            MetaInsightDaily::updateOrCreate(
                ['date' => $row['date_start'], 'level' => $level, 'meta_id' => $metaId],
                $this->values($account, $row) + ['synced_at' => $now],
            );
        }
        $this->ensureEntities($account, $level, $rows);
        return count($rows);
    }

    /**
     * Un rango fijo (§27) tal como lo calcula Meta (§57). Las entidades que ya no traen datos en ese rango se quitan del
     * estado actual (el histórico diario no se toca).
     */
    public function preset(MetaAdAccount $account, MetaClient $client, string $level, string $preset): int
    {
        $rows = $this->fetch($client, $account, $level, ['date_preset' => $preset]);
        $now = now();
        $seen = [];
        foreach ($rows as $row) {
            $metaId = self::entityId($row, $level);
            if (!$metaId) {
                continue;
            }
            $seen[] = $metaId;
            MetaInsightCurrent::updateOrCreate(
                ['range' => $preset, 'level' => $level, 'meta_id' => $metaId],
                $this->values($account, $row) + [
                    'date_start' => $row['date_start'] ?? null, 'date_stop' => $row['date_stop'] ?? null, 'synced_at' => $now,
                ],
            );
        }
        MetaInsightCurrent::where('meta_ad_account_id', $account->id)->where('range', $preset)->where('level', $level)
            ->when($seen, fn ($q) => $q->whereNotIn('meta_id', $seen))->delete();
        $this->ensureEntities($account, $level, $rows);
        return count($rows);
    }

    public static function entityId(array $row, string $level): ?string
    {
        $id = $row[$level . '_id'] ?? null;
        return $id !== null ? (string) $id : null;
    }

    /** Un rango día por día; si Meta pide menos datos, se parte en dos hasta llegar a un día. */
    private function fetchRange(MetaClient $client, MetaAdAccount $account, string $level, string $since, string $until): array
    {
        try {
            return $this->fetch($client, $account, $level, [
                'time_range' => ['since' => $since, 'until' => $until],
                'time_increment' => 1,
            ]);
        } catch (MetaApiException $e) {
            if ($e->kind !== MetaApiException::TOO_MUCH_DATA || $since === $until) {
                throw $e;
            }
            $a = Carbon::parse($since);
            $mid = $a->copy()->addDays(intdiv($a->diffInDays(Carbon::parse($until)), 2));
            return array_merge(
                $this->fetchRange($client, $account, $level, $since, $mid->toDateString()),
                $this->fetchRange($client, $account, $level, $mid->copy()->addDay()->toDateString(), $until),
            );
        }
    }

    private function fetch(MetaClient $client, MetaAdAccount $account, string $level, array $params): array
    {
        while (true) {
            $fields = array_values(array_diff(MetaInsightMapper::FIELDS, array_keys($this->dropped)));
            try {
                return $client->getAll($account->actId() . '/insights', $params + [
                    'level' => $level,
                    'fields' => implode(',', $fields),
                    'use_unified_attribution_setting' => true, // §16
                    'limit' => 500,
                ]);
            } catch (MetaApiException $e) {
                if ($e->kind === MetaApiException::FIELD
                    && preg_match('/\b([a-z0-9_]+) is not valid for fields param/i', $e->getMessage(), $m)
                    && in_array($m[1], $fields, true) && !isset($this->dropped[$m[1]])) {
                    $this->dropped[$m[1]] = true;
                    continue;
                }
                throw $e;
            }
        }
    }

    private function values(MetaAdAccount $account, array $row): array
    {
        return MetaInsightMapper::map($row) + [
            'meta_ad_account_id' => $account->id,
            'campaign_meta_id' => isset($row['campaign_id']) ? (string) $row['campaign_id'] : null,
            'adset_meta_id' => isset($row['adset_id']) ? (string) $row['adset_id'] : null,
            'ad_meta_id' => isset($row['ad_id']) ? (string) $row['ad_id'] : null,
        ];
    }

    /**
     * Si llegan métricas de una campaña, un ad set o un anuncio que no está guardado (por ejemplo, uno eliminado en
     * Meta), se guarda con el nombre que traen las métricas, para no perder sus datos (§13, §43).
     */
    private function ensureEntities(MetaAdAccount $account, string $level, array $rows): void
    {
        $byId = [];
        foreach ($rows as $row) {
            if ($id = self::entityId($row, $level)) {
                $byId[$id] = $row;
            }
        }
        if (!$byId) {
            return;
        }
        $model = ['campaign' => MetaCampaign::class, 'adset' => MetaAdset::class, 'ad' => MetaAd::class][$level];
        $known = $model::whereIn('meta_id', array_keys($byId))->pluck('meta_id')->all();
        foreach (array_diff(array_keys($byId), $known) as $id) {
            $row = $byId[$id];
            $base = ['meta_ad_account_id' => $account->id, 'meta_id' => (string) $id, 'name' => $row[$level . '_name'] ?? null,
                'missing_since' => now()];
            if ($level === 'campaign') {
                MetaCampaign::create($base);
                continue;
            }
            $campaign = MetaCampaign::where('meta_id', (string) ($row['campaign_id'] ?? ''))->first();
            $base += ['campaign_meta_id' => (string) ($row['campaign_id'] ?? ''), 'meta_campaign_id' => $campaign?->id];
            if ($level === 'adset') {
                MetaAdset::create($base);
                continue;
            }
            $adset = MetaAdset::where('meta_id', (string) ($row['adset_id'] ?? ''))->first();
            $ad = new MetaAd($base + ['adset_meta_id' => (string) ($row['adset_id'] ?? ''), 'meta_adset_id' => $adset?->id]);
            MetaHierarchySync::applyTrackingId($ad);
            $ad->save();
        }
    }
}
