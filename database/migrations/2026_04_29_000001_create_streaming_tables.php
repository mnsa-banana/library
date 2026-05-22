<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hard-cutover migration: drops watchmode_* tables and creates streaming_* tables.
// down() restores nothing — the repo is local-only and the legacy data is being
// deliberately discarded. Use `php artisan migrate:fresh` if you need to reset.
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('watchmode_title_sources');
        Schema::dropIfExists('watchmode_titles');
        Schema::dropIfExists('watchmode_sources');
        Schema::dropIfExists('watchmode_sync_log');

        Schema::create('streaming_services', function (Blueprint $t) {
            $t->string('id', 50)->primary();
            $t->string('name', 100);
            $t->string('theme_color', 20)->nullable();
            $t->string('logo_light', 500)->nullable();
            $t->string('logo_dark', 500)->nullable();
            $t->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('streaming_titles', function (Blueprint $t) {
            $t->string('id', 50)->primary();
            $t->string('imdb_id', 20)->nullable();
            $t->integer('tmdb_id')->nullable();
            $t->string('tmdb_type', 10)->nullable();
            $t->string('show_type', 20);
            $t->string('title', 500);
            $t->integer('release_year')->nullable();
            $t->integer('first_air_year')->nullable();
            $t->integer('last_air_year')->nullable();
            $t->integer('runtime')->nullable();
            $t->integer('rating')->nullable();
            $t->string('us_certification', 20)->nullable();
            $t->jsonb('genres')->nullable();
            $t->string('poster_url', 1000)->nullable();
            $t->text('overview')->nullable();
            $t->string('trailer_url', 500)->nullable();
            $t->softDeletesTz();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent();

            $t->index('imdb_id', 'idx_st_imdb_id');
            $t->index('tmdb_id', 'idx_st_tmdb_id');
        });

        Schema::create('streaming_title_offers', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('title_id', 50);
            $t->string('service_id', 50);
            $t->string('region', 2);
            $t->string('type', 20);
            $t->string('video_quality', 10)->nullable();
            $t->string('link', 1000);
            $t->string('deep_link', 1000)->nullable();
            $t->decimal('price_amount', 8, 2)->nullable();
            $t->string('price_currency', 3)->nullable();
            $t->timestampTz('expires_on')->nullable();
            $t->timestampTz('updated_at')->useCurrent();

            $t->foreign('title_id')->references('id')->on('streaming_titles')->cascadeOnDelete();
            $t->foreign('service_id')->references('id')->on('streaming_services')->cascadeOnDelete();
            $t->unique(['title_id', 'service_id', 'region', 'type', 'video_quality'], 'uq_streaming_title_offer');
            $t->index('title_id', 'idx_sto_title_id');
            $t->index(['service_id', 'region', 'type'], 'idx_sto_service_region_type');
        });

        Schema::create('streaming_sync_log', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('sync_type', 30);
            $t->timestampTz('started_at')->useCurrent();
            $t->timestampTz('completed_at')->nullable();
            $t->string('status', 20);
            $t->integer('api_calls_used')->default(0);
            $t->integer('titles_processed')->default(0);
            $t->string('last_cursor', 500)->nullable();
            $t->text('error_message')->nullable();
            $t->jsonb('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streaming_sync_log');
        Schema::dropIfExists('streaming_title_offers');
        Schema::dropIfExists('streaming_titles');
        Schema::dropIfExists('streaming_services');
    }
};
