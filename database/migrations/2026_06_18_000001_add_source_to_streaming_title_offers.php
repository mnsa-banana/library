<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Provenance for streaming_title_offers. MOTN (the daily /changes sync) is the
// sole writer/owner of 'motn' rows; additive discovery jobs write 'discovery'.
// MOTN's destructive paths are scoped to never touch a discovery row (see
// StreamingSync), letting two writers coexist on the same table.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('streaming_title_offers', function (Blueprint $t) {
            $t->string('source', 20)->default('motn');
        });
    }

    public function down(): void
    {
        Schema::table('streaming_title_offers', function (Blueprint $t) {
            $t->dropColumn('source');
        });
    }
};
