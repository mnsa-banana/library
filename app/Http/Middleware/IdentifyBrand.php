<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IdentifyBrand
{
    public function handle(Request $request, Closure $next)
    {
        // X-Brand header → ?brand= query → Host → default. The query override
        // is honored in non-production envs OR when the request host isn't a
        // configured brand domain (Railway previews, staging URLs, etc.) — on
        // real brand hosts in production the query is ignored so users can't
        // cross-brand via a crafted URL.
        $host = $request->getHost();
        $domainBrand = config('brands.domains')[$host] ?? null;

        $headerBrand = $request->header('X-Brand');
        $queryAllowed = ! app()->environment('production') || $domainBrand === null;
        $queryBrand = $queryAllowed ? $request->query('brand') : null;
        $candidate = $headerBrand ?: $queryBrand;

        if (is_string($candidate) && $candidate !== '' && config("brands.brands.{$candidate}")) {
            $brandKey = $candidate;
        } else {
            $brandKey = $domainBrand ?? config('brands.default');
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
