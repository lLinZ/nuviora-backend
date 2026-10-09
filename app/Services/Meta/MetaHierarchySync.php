<?php

namespace App\Services\Meta;

use App\Models\MetaAd;
use App\Models\MetaAdAccount;
use App\Models\MetaAdset;
use App\Models\MetaCampaign;
use App\Models\MetaCreative;
use Illuminate\Support\Arr;

/**
 * Campañas, ad sets y anuncios de una cuenta (documento de Fran del 2026-10-08, Módulo 2):
 * - se identifican por el ID de Meta; un cambio de nombre actualiza el mismo registro (§9, §10);
 * - lo que Meta deja de devolver no se borra: queda con su último estado conocido y la fecha (§42, §43);
 * - cada anuncio guarda su creativo de Meta y el creative_tracking_id detectado, salvo que el Admin lo haya puesto a
 *   mano (§32).
 * Una llamada por nivel y cuenta, con paginación (§52: no una llamada por anuncio).
 */
class MetaHierarchySync
{
    /** Todos los estados menos DELETED, para que también lleguen los pausados y archivados (§42). */
    public const STATUSES = ['ACTIVE', 'PAUSED', 'ARCHIVED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED', 'IN_PROCESS', 'WITH_ISSUES',
        'PENDING_REVIEW', 'DISAPPROVED', 'PREAPPROVED', 'PENDING_BILLING_INFO'];

    private const CAMPAIGN_FIELDS = 'id,name,status,effective_status,objective,created_time,updated_time,start_time,stop_time,buying_type,daily_budget,lifetime_budget,bid_strategy';
    private const ADSET_FIELDS = 'id,name,campaign_id,status,effective_status,optimization_goal,billing_event,attribution_spec,promoted_object,created_time,updated_time,daily_budget,lifetime_budget';
    private const AD_FIELDS = 'id,name,adset_id,campaign_id,status,effective_status,created_time,updated_time,creative{id,name,object_type,video_id,image_hash,thumbnail_url}';

    /** @return int registros procesados */
    public function sync(MetaAdAccount $account, MetaClient $client): int
    {
        $act = $account->actId();
        $now = now();

        $campaigns = $this->fetch($client, "{$act}/campaigns", self::CAMPAIGN_FIELDS);
        $campaignIds = [];
        foreach ($campaigns as $row) {
            $c = MetaCampaign::updateOrCreate(['meta_id' => (string) $row['id']], [
                'meta_ad_account_id' => $account->id,
                'name' => $row['name'] ?? null,
                'status' => $row['status'] ?? null,
                'effective_status' => $row['effective_status'] ?? null,
                'objective' => $row['objective'] ?? null,
                'created_time' => $this->time($row['created_time'] ?? null),
                'start_time' => $this->time($row['start_time'] ?? null),
                'stop_time' => $this->time($row['stop_time'] ?? null),
                'last_known_state' => $row['effective_status'] ?? null,
                'last_seen_at' => $now,
                'missing_since' => null,
                'meta' => Arr::except($row, ['id', 'name', 'status', 'effective_status', 'objective', 'created_time', 'start_time', 'stop_time']),
            ]);
            $campaignIds[$c->meta_id] = $c->id;
        }
        $this->markMissing(MetaCampaign::class, $account, array_keys($campaignIds), $now);
        $campaignIds += MetaCampaign::where('meta_ad_account_id', $account->id)->pluck('id', 'meta_id')->all();

        $adsets = $this->fetch($client, "{$act}/adsets", self::ADSET_FIELDS);
        $adsetIds = [];
        foreach ($adsets as $row) {
            $campaignMetaId = (string) ($row['campaign_id'] ?? '');
            $s = MetaAdset::updateOrCreate(['meta_id' => (string) $row['id']], [
                'meta_ad_account_id' => $account->id,
                'meta_campaign_id' => $campaignIds[$campaignMetaId] ?? null,
                'campaign_meta_id' => $campaignMetaId,
                'name' => $row['name'] ?? null,
                'status' => $row['status'] ?? null,
                'effective_status' => $row['effective_status'] ?? null,
                'optimization_goal' => $row['optimization_goal'] ?? null,
                'attribution_spec' => $row['attribution_spec'] ?? null,
                'created_time' => $this->time($row['created_time'] ?? null),
                'last_known_state' => $row['effective_status'] ?? null,
                'last_seen_at' => $now,
                'missing_since' => null,
                'meta' => Arr::except($row, ['id', 'name', 'campaign_id', 'status', 'effective_status', 'optimization_goal', 'attribution_spec', 'created_time']),
            ]);
            $adsetIds[$s->meta_id] = $s->id;
        }
        $this->markMissing(MetaAdset::class, $account, array_keys($adsetIds), $now);
        $adsetIds += MetaAdset::where('meta_ad_account_id', $account->id)->pluck('id', 'meta_id')->all();

        $ads = $this->fetch($client, "{$act}/ads", self::AD_FIELDS);
        $seenAds = [];
        foreach ($ads as $row) {
            $creative = $row['creative'] ?? [];
            $ad = MetaAd::firstOrNew(['meta_id' => (string) $row['id']]);
            $ad->fill([
                'meta_ad_account_id' => $account->id,
                'meta_campaign_id' => $campaignIds[(string) ($row['campaign_id'] ?? '')] ?? null,
                'meta_adset_id' => $adsetIds[(string) ($row['adset_id'] ?? '')] ?? null,
                'campaign_meta_id' => (string) ($row['campaign_id'] ?? ''),
                'adset_meta_id' => (string) ($row['adset_id'] ?? ''),
                'name' => $row['name'] ?? null,
                'status' => $row['status'] ?? null,
                'effective_status' => $row['effective_status'] ?? null,
                'created_time' => $this->time($row['created_time'] ?? null),
                'meta_creative_id' => isset($creative['id']) ? (string) $creative['id'] : null,
                'creative_type' => self::creativeType($creative),
                'video_id' => isset($creative['video_id']) ? (string) $creative['video_id'] : null,
                'image_hash' => $creative['image_hash'] ?? null,
                'thumbnail_url' => $creative['thumbnail_url'] ?? null,
                'last_known_state' => $row['effective_status'] ?? null,
                'last_seen_at' => $now,
                'missing_since' => null,
                'meta' => Arr::except($row, ['id', 'name', 'adset_id', 'campaign_id', 'status', 'effective_status', 'created_time']),
            ]);
            self::applyTrackingId($ad);
            $ad->save();
            $seenAds[] = $ad->meta_id;
        }
        $this->markMissing(MetaAd::class, $account, $seenAds, $now);

        return count($campaigns) + count($adsets) + count($ads);
    }

    /**
     * El creative_tracking_id de un anuncio: el detectado por el nombre, salvo que el Admin lo haya puesto a mano (§32:
     * "la relación debe poder corregirse manualmente"). Enlaza el anuncio con su creativo (§33).
     */
    public static function applyTrackingId(MetaAd $ad): void
    {
        if ($ad->tracking_id_source !== 'manual') {
            $detected = CreativeTrackingId::detect($ad->name);
            $ad->creative_tracking_id = $detected;
            $ad->tracking_id_source = $detected ? 'auto' : null;
        }
        $ad->meta_creative_ref_id = $ad->creative_tracking_id
            ? self::creativeFor($ad->creative_tracking_id, $ad->creative_type)->id
            : null;
    }

    public static function creativeFor(string $trackingId, ?string $type): MetaCreative
    {
        $creative = MetaCreative::firstOrCreate(['tracking_id' => $trackingId], ['type' => $type]);
        if ($type && $creative->type !== $type) {
            $creative->type = $creative->type === null ? $type : 'mixed';
            $creative->save();
        }
        return $creative;
    }

    /** Video o imagen, según lo que diga Meta del creativo (§20, §23). */
    public static function creativeType(array $creative): ?string
    {
        if (!empty($creative['video_id']) || strtoupper((string) ($creative['object_type'] ?? '')) === 'VIDEO') {
            return 'video';
        }
        if (!empty($creative['image_hash']) || in_array(strtoupper((string) ($creative['object_type'] ?? '')), ['PHOTO', 'SHARE', 'IMAGE'], true)) {
            return 'image';
        }
        return null;
    }

    private function fetch(MetaClient $client, string $path, string $fields): array
    {
        try {
            return $client->getAll($path, ['fields' => $fields, 'limit' => 200, 'effective_status' => self::STATUSES]);
        } catch (MetaApiException $e) {
            if ($e->kind !== MetaApiException::FIELD && $e->kind !== MetaApiException::META) {
                throw $e;
            }
            // Si Meta no acepta el filtro de estados, se pide sin él (solo llegan los no archivados).
            return $client->getAll($path, ['fields' => $fields, 'limit' => 200]);
        }
    }

    /** §43: lo que no volvió conserva sus datos; solo se anota desde cuándo falta. */
    private function markMissing(string $model, MetaAdAccount $account, array $seen, $now): void
    {
        $model::where('meta_ad_account_id', $account->id)
            ->whereNull('missing_since')
            ->when($seen, fn ($q) => $q->whereNotIn('meta_id', $seen))
            ->update(['missing_since' => $now]);
    }

    private function time(?string $value): ?string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->setTimezone(config('app.timezone'))->toDateTimeString() : null;
    }
}
