<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_library_titles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('work_key', 50)->nullable()->unique('uq_blt_work_key');
            $t->string('title');
            $t->string('author')->nullable();
            $t->smallInteger('year')->nullable();
            $t->string('normalized_title');
            $t->string('normalized_author')->nullable();
            $t->jsonb('isbn13s')->default('[]');
            $t->string('cover_url', 500)->nullable();
            $t->text('description')->nullable();
            $t->jsonb('categories')->nullable();
            $t->integer('page_count')->nullable();
            $t->smallInteger('min_age')->nullable();
            $t->string('min_age_source', 30)->nullable();
            $t->string('google_books_id')->nullable();
            $t->boolean('preview_available')->nullable();
            $t->timestampTz('enriched_at')->nullable();
            $t->timestampsTz();

            $t->index('normalized_title', 'idx_blt_normalized_title');
            $t->index('normalized_author', 'idx_blt_normalized_author');
        });

        // GIN index for jsonb containment lookups on isbn13s (WorkResolver
        // step 1). pgsql only — sqlite test runs skip it (same guard pattern
        // as 2026_06_10_000002_replace_uq_content_with_identity_indexes).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX idx_blt_isbn13s_gin '
                .'ON book_library_titles USING gin (isbn13s)'
            );
        }

        Schema::create('book_list_memberships', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('library_title_id');
            $t->string('list_source', 30);
            $t->string('list_key', 100);
            $t->smallInteger('rank')->nullable();
            $t->smallInteger('weeks_on_list')->nullable();
            $t->date('as_of_date')->nullable();
            $t->string('review_url', 500)->nullable();
            $t->jsonb('metadata')->nullable();

            $t->foreign('library_title_id')->references('id')->on('book_library_titles')->cascadeOnDelete();
            $t->unique(['library_title_id', 'list_source', 'list_key'], 'uq_book_list_membership');
            $t->index(['list_source', 'list_key'], 'idx_blm_source_key');
            $t->index('as_of_date', 'idx_blm_as_of_date');
        });

        Schema::create('book_sync_log', function (Blueprint $t) {
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
        Schema::dropIfExists('book_sync_log');
        Schema::dropIfExists('book_list_memberships');
        Schema::dropIfExists('book_library_titles');
    }
};
