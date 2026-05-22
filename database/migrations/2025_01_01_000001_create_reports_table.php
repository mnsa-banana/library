<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('content_type', 10);
            $table->string('title', 500);
            $table->string('year', 10)->nullable();
            $table->text('poster_url')->nullable();
            $table->string('certification', 20)->nullable();
            $table->text('plot_synopsis')->nullable();
            $table->text('critical_reception')->nullable();
            $table->text('parent_summary')->nullable();
            $table->boolean('is_adaptation')->default(false);
            $table->text('source_material')->nullable();
            $table->timestampTz('published_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['content_type', 'title', 'year'], 'uq_content');
            $table->index('content_type', 'idx_reports_content_type');
            $table->index('published_at', 'idx_reports_published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
