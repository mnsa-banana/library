<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaShellTest extends TestCase
{
    public function test_root_returns_spa_shell(): void
    {
        // public/build/index.html is created by `npm run build`. In CI / dev
        // we may not have it — write a placeholder if missing to keep this
        // test independent of the frontend build.
        $shellPath = public_path('build/index.html');
        if (! file_exists($shellPath)) {
            @mkdir(dirname($shellPath), 0777, true);
            file_put_contents($shellPath, '<!doctype html><html><body><div id="root"></div></body></html>');
        }

        $this->get('/')->assertOk();
    }

    public function test_arbitrary_path_returns_spa_shell(): void
    {
        $shellPath = public_path('build/index.html');
        if (! file_exists($shellPath)) {
            @mkdir(dirname($shellPath), 0777, true);
            file_put_contents($shellPath, '<!doctype html><html><body><div id="root"></div></body></html>');
        }

        $this->get('/some-spa-route')->assertOk();
    }
}
