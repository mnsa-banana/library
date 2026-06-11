<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Replace the all-rows uq_content UNIQUE (content_type, title, year)
     * with the two partial identity indexes the admin publish pipeline
     * upserts against:
     *
     *  - movies/TV:  UNIQUE (content_type, tmdb_id) WHERE tmdb_id IS NOT NULL
     *  - books:      UNIQUE (content_type, title, year) WHERE tmdb_id IS NULL
     *
     * uq_content made two same-title-same-year works (distinct tmdb_ids)
     * impossible to publish side by side — the insert missed the tmdb
     * conflict target and raised a unique violation. Identity for movies/TV
     * is tmdb_id; title+year remains the identity for books only.
     *
     * Idempotent: the tmdb index may already exist (it was first applied
     * directly to the live DBs on 2026-06-10).
     */
    public function up(): void
    {
        // Books index FIRST so book upserts never lack a conflict target.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_reports_books_title_year '
            .'ON reports (content_type, title, year) WHERE tmdb_id IS NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_reports_content_type_tmdb_id '
            .'ON reports (content_type, tmdb_id) WHERE tmdb_id IS NOT NULL'
        );
        // On Postgres $table->unique() created uq_content as a table constraint;
        // on sqlite (tests) it is a plain index, and DROP CONSTRAINT isn't valid syntax.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS uq_content');
        }
        // Some environments may carry uq_content as a bare index rather
        // than a constraint (create_all-era drift) — drop that form too.
        DB::statement('DROP INDEX IF EXISTS uq_content');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE reports ADD CONSTRAINT uq_content '
                .'UNIQUE (content_type, title, year)'
            );
        } else {
            DB::statement('CREATE UNIQUE INDEX uq_content ON reports (content_type, title, year)');
        }
        DB::statement('DROP INDEX IF EXISTS uq_reports_content_type_tmdb_id');
        DB::statement('DROP INDEX IF EXISTS uq_reports_books_title_year');
    }
};
