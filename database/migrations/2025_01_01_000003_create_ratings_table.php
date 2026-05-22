<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('section_key', 100);
            $table->string('group_key', 100);
            $table->string('subcategory_key', 100);
            $table->boolean('present')->nullable();
            $table->string('level', 50)->nullable();
            $table->text('evidence');

            $table->unique(
                ['report_id', 'section_key', 'group_key', 'subcategory_key'],
                'uq_rating'
            );
            $table->index('report_id', 'idx_ratings_report');
            $table->index(['section_key', 'group_key', 'subcategory_key'], 'idx_ratings_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
