<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('streaming_titles', function (Blueprint $t) {
            $t->boolean('netflix_kids_surfaced')->nullable();
            $t->timestampTz('netflix_kids_checked_at')->nullable();
            $t->index('netflix_kids_surfaced', 'idx_st_nf_kids_surfaced');
        });
    }

    public function down(): void
    {
        Schema::table('streaming_titles', function (Blueprint $t) {
            $t->dropIndex('idx_st_nf_kids_surfaced');
            $t->dropColumn(['netflix_kids_surfaced', 'netflix_kids_checked_at']);
        });
    }
};
