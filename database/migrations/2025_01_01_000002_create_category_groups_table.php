<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('section_key', 100);
            $table->string('group_key', 100);
            $table->text('notes')->nullable();

            $table->unique(['report_id', 'section_key', 'group_key'], 'uq_group');
            $table->index('report_id', 'idx_cg_report');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_groups');
    }
};
