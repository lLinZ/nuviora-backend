<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integración de Meta Ads (documento de Fran del 2026-10-08, Módulo 2), datos de las cuentas:
 * - meta_campaigns, meta_adsets y meta_ads: la jerarquía, identificada siempre por el ID de Meta (§9, §10). Nada se
 *   borra porque Meta deje de devolverlo: queda el último estado conocido (§42, §43). La campaña lleva su producto y su
 *   ciudad (§11); los ad sets y anuncios los heredan (§12).
 * - meta_creatives: un creativo propio (creative_tracking_id) con su tipo y su estado manual (§32, §33, §41).
 * - meta_insights_daily: el histórico, una fila por fecha + nivel + ID de Meta, con los valores base (§24, §25, §50).
 * - meta_insights_current: el estado actual de cada rango fijo, como lo calcula Meta (§27, §57).
 * - product_ad_targets: Target CPA y Break-even por producto, con su vigencia (§34, §49).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_ad_account_id')->constrained()->cascadeOnDelete();
            $table->string('meta_id', 32)->unique();
            $table->string('name')->nullable();
            $table->string('status', 24)->nullable();
            $table->string('effective_status', 32)->nullable();
            $table->string('objective', 48)->nullable();
            $table->timestamp('created_time')->nullable();
            $table->timestamp('start_time')->nullable();
            $table->timestamp('stop_time')->nullable();
            // §11: un solo producto y una sola ciudad, elegidos por el Admin
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('classified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('classified_at')->nullable();
            $this->tracking($table);
            $table->index(['product_id', 'city_id']);
        });

        Schema::create('meta_adsets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_ad_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('campaign_meta_id', 32)->index();
            $table->string('meta_id', 32)->unique();
            $table->string('name')->nullable();
            $table->string('status', 24)->nullable();
            $table->string('effective_status', 32)->nullable();
            $table->string('optimization_goal', 48)->nullable();
            $table->json('attribution_spec')->nullable(); // §9: configuración de atribución
            $table->timestamp('created_time')->nullable();
            $this->tracking($table);
        });

        Schema::create('meta_creatives', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_id', 120)->unique(); // §32: creative_tracking_id interno
            $table->string('type', 8)->nullable();        // video, image o mixed (§20, §23)
            // §41: Testing, Winner, Fatigued, Loser, Archived… asignados a mano
            $table->string('status', 24)->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('meta_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_ad_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('meta_adset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('campaign_meta_id', 32)->index();
            $table->string('adset_meta_id', 32)->index();
            $table->string('meta_id', 32)->unique();
            $table->string('name')->nullable();
            $table->string('status', 24)->nullable();
            $table->string('effective_status', 32)->nullable();
            $table->timestamp('created_time')->nullable();
            $table->string('meta_creative_id', 32)->nullable(); // §9: Meta Creative ID cuando esté disponible
            $table->string('creative_type', 8)->nullable();     // video o image (§23)
            $table->string('video_id', 32)->nullable();
            $table->string('image_hash', 64)->nullable();
            $table->text('thumbnail_url')->nullable();
            // §32: detectado por la nomenclatura (auto) o corregido a mano (manual); el manual no se pisa
            $table->foreignId('meta_creative_ref_id')->nullable()->constrained('meta_creatives')->nullOnDelete();
            $table->string('creative_tracking_id', 120)->nullable()->index();
            $table->string('tracking_id_source', 8)->nullable();
            $this->tracking($table);
        });

        Schema::create('meta_insights_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('level', 8); // campaign, adset, ad (§14)
            $table->string('meta_id', 32);
            $this->insightColumns($table);
            $table->unique(['date', 'level', 'meta_id']); // §25: fecha + entidad Meta + nivel, sin duplicados
            $table->index(['level', 'date']);
            $table->index(['campaign_meta_id', 'date']);
        });

        Schema::create('meta_insights_current', function (Blueprint $table) {
            $table->id();
            $table->string('range', 24); // last_3d, last_7d, … (§27)
            $table->date('date_start')->nullable();
            $table->date('date_stop')->nullable();
            $table->string('level', 8);
            $table->string('meta_id', 32);
            $this->insightColumns($table);
            $table->unique(['range', 'level', 'meta_id']);
            $table->index(['range', 'level']);
        });

        Schema::create('product_ad_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('target_cpa', 10, 2)->nullable();
            $table->decimal('break_even_cpa', 10, 2)->nullable();
            $table->date('valid_from'); // §49: vigencia, para conservar el histórico de cambios
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'valid_from']);
        });
    }

    /** Lo que se conserva de cada objeto aunque Meta deje de devolverlo (§42, §43), y "demás metadata útil" (§9). */
    private function tracking(Blueprint $table): void
    {
        $table->string('last_known_state', 32)->nullable();
        $table->timestamp('last_seen_at')->nullable();
        $table->timestamp('missing_since')->nullable(); // Meta dejó de devolverlo
        $table->json('meta')->nullable();
        $table->timestamps();
    }

    /** Los valores base (§50) de una fila de Insights. Vacío = Meta no lo devolvió, que no es lo mismo que 0. */
    private function insightColumns(Blueprint $table): void
    {
        $table->foreignId('meta_ad_account_id')->constrained()->cascadeOnDelete();
        $table->string('campaign_meta_id', 32)->nullable();
        $table->string('adset_meta_id', 32)->nullable();
        $table->string('ad_meta_id', 32)->nullable();
        $table->decimal('spend', 14, 2)->default(0);
        $table->unsignedBigInteger('impressions')->default(0);
        $table->unsignedBigInteger('reach')->nullable();
        $table->decimal('frequency', 12, 6)->nullable();
        $table->unsignedBigInteger('clicks')->nullable();
        $table->unsignedBigInteger('link_clicks')->nullable();
        $table->unsignedBigInteger('outbound_clicks')->nullable();
        $table->unsignedBigInteger('landing_page_views')->nullable();
        $table->decimal('purchases', 14, 4)->nullable();
        $table->decimal('purchase_value', 14, 2)->nullable();
        $table->unsignedBigInteger('video_plays')->nullable();
        $table->unsignedBigInteger('video_3s_plays')->nullable();
        $table->unsignedBigInteger('thruplays')->nullable();
        $table->unsignedBigInteger('video_p25')->nullable();
        $table->unsignedBigInteger('video_p50')->nullable();
        $table->unsignedBigInteger('video_p75')->nullable();
        $table->unsignedBigInteger('video_p95')->nullable();
        $table->unsignedBigInteger('video_p100')->nullable();
        $table->decimal('video_avg_time', 10, 3)->nullable(); // segundos
        $table->json('actions')->nullable(); // todas las acciones, para recalcular sin volver a pedir (§50)
        $table->timestamp('synced_at')->nullable();
        $table->timestamps();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ad_targets');
        Schema::dropIfExists('meta_insights_current');
        Schema::dropIfExists('meta_insights_daily');
        Schema::dropIfExists('meta_ads');
        Schema::dropIfExists('meta_creatives');
        Schema::dropIfExists('meta_adsets');
        Schema::dropIfExists('meta_campaigns');
    }
};
