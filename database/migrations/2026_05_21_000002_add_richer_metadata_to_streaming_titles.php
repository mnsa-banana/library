<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds richer per-title metadata pulled from /shows responses (cast, directors,
// creators, season/episode counts, larger backdrop image) plus `available_from`
// on offers so /changes?change_type=upcoming can pre-record future drops.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('streaming_titles', function (Blueprint $t) {
            $t->jsonb('cast_members')->nullable()->after('genres');
            $t->jsonb('directors')->nullable()->after('cast_members');
            $t->jsonb('creators')->nullable()->after('directors');
            $t->integer('season_count')->nullable()->after('creators');
            $t->integer('episode_count')->nullable()->after('season_count');
            $t->string('backdrop_url', 1000)->nullable()->after('poster_url');
        });

        Schema::table('streaming_title_offers', function (Blueprint $t) {
            $t->timestampTz('available_from')->nullable()->after('expires_on');
            $t->index('available_from', 'idx_sto_available_from');
        });
    }

    public function down(): void
    {
        Schema::table('streaming_title_offers', function (Blueprint $t) {
            $t->dropIndex('idx_sto_available_from');
            $t->dropColumn('available_from');
        });

        Schema::table('streaming_titles', function (Blueprint $t) {
            $t->dropColumn(['cast_members', 'directors', 'creators', 'season_count', 'episode_count', 'backdrop_url']);
        });
    }
};
