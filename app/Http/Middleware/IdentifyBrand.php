<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IdentifyBrand
{
    public function handle(Request $request, Closure $next)
    {
        // X-Brand header → ?brand= query (local/testing only) → Host → default.
        $headerBrand = $request->header('X-Brand');
        $queryBrand = app()->environment('local', 'testing') ? $request->query('brand') : null;
        $candidate = $headerBrand ?: $queryBrand;

        if (is_string($candidate) && $candidate !== '' && config("brands.brands.{$candidate}")) {
            $brandKey = $candidate;
        } else {
            $host = $request->getHost();
            $brandKey = config('brands.domains')[$host] ?? config('brands.default');
        }
        $brand = config("brands.brands.{$brandKey}", config("brands.brands." . config('brands.default')));

        $request->attributes->set('brand_key', $brandKey);
        $request->attributes->set('brand', $brand);
        $request->attributes->set('revenuecat_entitlement_id', $brand['entitlement_id']);
        $request->attributes->set('revenuecat_secret_key', $brand['revenuecat_secret_key']);
        $request->attributes->set('revenuecat_purchase_link_url', $brand['purchase_link_url']);

        return $next($request);
    }
}
