<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Records when the upstream streaming-availability API returns multiple offers
// for the same (service, region, type, quality) tuple — these get collapsed to
// one row in streaming_title_offers by the unique constraint, losing data.
// The jsonb maps service_id to collapsed offer count, e.g. {"netflix": 5}
// means the upstream returned 5 Netflix listings that all map to this TMDB id.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('streaming_titles', function (Blueprint $t) {
            $t->jsonb('umbrella_services')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('streaming_titles', function (Blueprint $t) {
            $t->dropColumn('umbrella_services');
        });
    }
};
