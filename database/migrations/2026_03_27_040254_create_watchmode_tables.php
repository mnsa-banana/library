<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchmode_sources', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('name', 255);
            $table->string('type', 20);
            $table->string('logo_url', 500)->nullable();
            $table->jsonb('regions')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('watchmode_titles', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('title', 500);
            $table->string('type', 30);
            $table->integer('year')->nullable();
            $table->string('imdb_id', 20)->nullable();
            $table->integer('tmdb_id')->nullable();
            $table->string('tmdb_type', 10)->nullable();

            // TMDB enrichment
            $table->string('us_rating', 20)->nullable();
            $table->jsonb('genre_names')->nullable();

            // Watchmode detail fields
            $table->string('poster', 500)->nullable();
            $table->text('plot_overview')->nullable();
            $table->integer('runtime_minutes')->nullable();
            $table->float('user_rating')->nullable();
            $table->float('critic_score')->nullable();
            $table->float('relevance_percentile')->nullable();
            $table->string('trailer', 500)->nullable();
            $table->jsonb('network_names')->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('imdb_id', 'idx_wt_imdb_id');
            $table->index('tmdb_id', 'idx_wt_tmdb_id');
        });

        Schema::create('watchmode_title_sources', function (Blueprint $table) {
            $table->id();
            $table->integer('title_id');
            $table->integer('source_id');
            $table->string('type', 20);
            $table->string('region', 10);
            $table->string('web_url', 500)->nullable();
            $table->string('format', 10)->nullable();
            $table->decimal('price', 8, 2)->nullable();
            $table->integer('seasons')->nullable();
            $table->integer('episodes')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('title_id')->references('id')->on('watchmode_titles')->cascadeOnDelete();
            $table->foreign('source_id')->references('id')->on('watchmode_sources')->cascadeOnDelete();
            $table->unique(['title_id', 'source_id', 'type', 'region', 'format'], 'uq_title_source');
            $table->index('title_id', 'idx_wts_title_id');
        });

        Schema::create('watchmode_sync_log', function (Blueprint $table) {
            $table->id();
            $table->string('sync_type', 30);
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->string('status', 20);
            $table->integer('credits_used')->default(0);
            $table->integer('titles_processed')->default(0);
            $table->integer('last_page')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchmode_title_sources');
        Schema::dropIfExists('watchmode_titles');
        Schema::dropIfExists('watchmode_sources');
        Schema::dropIfExists('watchmode_sync_log');
    }
};
