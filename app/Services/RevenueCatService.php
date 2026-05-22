<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class RevenueCatService
{
    private string $baseUrl = 'https://api.revenuecat.com/v1';

    public function isSubscribed(string $appUserId, string $entitlementId, string $secretKey = ''): bool
    {
        $subscriber = $this->getSubscriber($appUserId, $secretKey);

        if (!$subscriber) return false;

        $entitlements = $subscriber['subscriber']['entitlements'] ?? [];

        if (!isset($entitlements[$entitlementId])) return false;

        $expiresDate = $entitlements[$entitlementId]['expires_date'] ?? null;

        // null expires_date means lifetime/non-expiring
        if ($expiresDate === null) return true;

        return now()->lt($expiresDate);
    }

    public function getSubscriber(string $appUserId, string $secretKey = ''): ?array
    {
        $key = $secretKey ?: config('services.revenuecat.secret_key');
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $key,
            'Content-Type' => 'application/json',
        ])->get("{$this->baseUrl}/subscribers/{$appUserId}");

        if ($response->failed()) return null;

        return $response->json();
    }
}
