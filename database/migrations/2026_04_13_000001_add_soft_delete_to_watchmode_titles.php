<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watchmode_titles', function (Blueprint $table) {
            $table->timestampTz('deleted_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('watchmode_titles', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }
};
