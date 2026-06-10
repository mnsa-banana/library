<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The database cache store (CACHE_STORE=database, the default) reads and
     * writes these tables: `cache` backs Cache::get/put and the rate limiters
     * registered in AppServiceProvider; `cache_locks` backs atomic locks such
     * as the streaming:update overlap guard. Neither existed before this
     * migration, so any lock or rate-limit hit on a fresh database would throw.
     */
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }
};
