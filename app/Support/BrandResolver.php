<?php

namespace App\Support;

use Illuminate\Http\Request;

class BrandResolver
{
    public function fromRequest(Request $request): Brand
    {
        $header = $request->header('X-Brand');
        $candidate = is_string($header) && $header !== '' ? $header : null;

        if (! $candidate || ! config("brands.brands.{$candidate}")) {
            $host = $request->getHost();
            $candidate = config('brands.domains')[$host] ?? null;
        }

        return $this->fromKey($candidate ?? config('brands.default'));
    }

    public function fromKey(string $key): Brand
    {
        $brands = config('brands.brands');
        $cfg = $brands[$key] ?? $brands[config('brands.default')];
        $resolvedKey = isset($brands[$key]) ? $key : config('brands.default');

        return new Brand(
            key: $resolvedKey,
            name: $cfg['name'],
            accentHex: $cfg['accent_hex'],
            mailFromAddress: $cfg['mail_from_address'],
            mailFromName: $cfg['mail_from_name'],
            allowedOrigin: $cfg['allowed_origin'],
            spaOriginLocal: $cfg['spa_origin_local'],
            customerCenterUrlPattern: $cfg['customer_center_url_pattern'],
            mailLogoUrl: $cfg['mail_logo_url'] ?? null,
        );
    }
}
