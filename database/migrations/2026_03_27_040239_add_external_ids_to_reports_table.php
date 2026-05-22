<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('imdb_id', 20)->nullable()->after('source_material');
            $table->integer('tmdb_id')->nullable()->after('imdb_id');

            $table->index('imdb_id');
            $table->index('tmdb_id');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['tmdb_id']);
            $table->dropIndex(['imdb_id']);
            $table->dropColumn(['imdb_id', 'tmdb_id']);
        });
    }
};
