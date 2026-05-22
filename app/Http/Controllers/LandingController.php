<?php

namespace App\Http\Controllers;

use App\Support\SpaShell;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    public function __invoke(Request $request)
    {
        $brandKey = $request->attributes->get('brand_key', config('brands.default'));

        if (! str_starts_with($brandKey, 'mnsa')) {
            return SpaShell::respond();
        }

        // App-route links (→ the React SPA). In prod the SPA is same-origin so
        // these stay relative; in local dev FRONTEND_URL points at the Vite
        // server (a different origin), where the SPA can't read the host for
        // brand detection — so carry ?brand= along.
        $spaBase = rtrim((string) config('app.frontend_url'), '/');
        $brandQuery = ($spaBase !== '' && config('app.env') === 'local')
            ? '?brand='.urlencode($brandKey)
            : '';
        $appUrl = fn (string $path) => $spaBase.$path.$brandQuery;

        return view('landing.mnsa', [
            'brandKey' => $brandKey,
            'copy' => config("mnsa_landing.brands.{$brandKey}", config('mnsa_landing.brands.mnsa-safe')),
            'registerUrl' => $appUrl('/register'),
            'loginUrl' => $appUrl('/login'),
        ]);
    }
}
