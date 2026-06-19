<?php

namespace Tests;

use App\Models\StreamingService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The static "ensured services" cache survives a RefreshDatabase rollback;
        // clear it so a service ensured in one test can't make a later test skip
        // (re)inserting the rolled-back row.
        StreamingService::clearEnsuredCache();
    }
}
