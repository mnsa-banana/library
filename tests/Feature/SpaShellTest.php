<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SpaShellTest extends TestCase
{
    public function test_serves_index_html_when_built(): void
    {
        File::ensureDirectoryExists(public_path('build'));
        $existed = File::exists(public_path('build/index.html'));
        if (! $existed) {
            File::put(public_path('build/index.html'), '<!doctype html><div id="root">SPA</div>');
        }

        $this->get('/some/spa/route')->assertOk()->assertSee('id="root"', false);

        if (! $existed) {
            File::delete(public_path('build/index.html'));
        }
    }
}
