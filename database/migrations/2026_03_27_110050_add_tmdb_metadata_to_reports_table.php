<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->float('rating')->nullable()->after('certification');
            $table->integer('runtime')->nullable()->after('rating');
            $table->string('overview', 2000)->nullable()->after('runtime');
            $table->string('directors', 500)->nullable()->after('overview');
            $table->string('creators', 500)->nullable()->after('directors');
            $table->string('top_cast', 1000)->nullable()->after('creators');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['rating', 'runtime', 'overview', 'directors', 'creators', 'top_cast']);
        });
    }
};
