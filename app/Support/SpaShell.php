<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

class SpaShell
{
    /** Invokable so it can be used as a route action (`Route::fallback(SpaShell::class)`) — closure routes aren't cacheable. */
    public function __invoke(): \Symfony\Component\HttpFoundation\Response
    {
        return self::respond();
    }

    /** The prebuilt React SPA shell, or a 500 if the frontend hasn't been built. */
    public static function respond(): \Symfony\Component\HttpFoundation\Response
    {
        $path = public_path('build/index.html');

        if (! File::exists($path)) {
            return response('Frontend not built. Run: npm run build --prefix frontend', 500);
        }

        return response(File::get($path), 200, ['Content-Type' => 'text/html']);
    }
}
