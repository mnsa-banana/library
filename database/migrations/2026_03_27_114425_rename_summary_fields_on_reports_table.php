<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->renameColumn('plot_synopsis', 'summary');
            $table->renameColumn('critical_reception', 'reception');
            $table->renameColumn('parent_summary', 'heads_up');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->renameColumn('summary', 'plot_synopsis');
            $table->renameColumn('reception', 'critical_reception');
            $table->renameColumn('heads_up', 'parent_summary');
        });
    }
};
