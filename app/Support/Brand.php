<?php

namespace App\Support;

final class Brand
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $accentHex,
        public readonly string $mailFromAddress,
        public readonly string $mailFromName,
        public readonly string $allowedOrigin,
        public readonly string $spaOriginLocal,
        public readonly string $customerCenterUrlPattern,
        public readonly ?string $mailLogoUrl,
    ) {}

    public function spaOrigin(): string
    {
        return app()->environment('local', 'testing')
            ? $this->spaOriginLocal
            : $this->allowedOrigin;
    }

    public function customerCenterUrl(string $appUserId): string
    {
        if (! str_contains($this->customerCenterUrlPattern, '{appUserId}')) {
            throw new \RuntimeException(
                "Brand [{$this->key}] customer_center_url_pattern is missing the {appUserId} placeholder."
            );
        }

        return str_replace('{appUserId}', rawurlencode($appUserId), $this->customerCenterUrlPattern);
    }
}
