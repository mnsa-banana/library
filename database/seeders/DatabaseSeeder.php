<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Real data arrives via pg_dump restore from sponge-kids (one-time seed)
        // and thereafter only via the cron pipelines.
    }
}
