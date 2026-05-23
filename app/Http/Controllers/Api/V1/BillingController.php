<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\RevenueCatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function status(Request $request, RevenueCatService $rc): JsonResponse
    {
        $appUserId = (string) $request->user()->id;
        $secretKey = (string) config('services.revenuecat.secret_key', '');
        $subscriber = $rc->getSubscriber($appUserId, $secretKey);
        $entitlementId = config('services.revenuecat.entitlement_id');

        $entitlements = $subscriber['subscriber']['entitlements'] ?? [];
        $entitlement = $entitlements[$entitlementId] ?? null;

        $subscribed = $entitlement && (
            $entitlement['expires_date'] === null ||
            now()->lt($entitlement['expires_date'])
        );

        return response()->json([
            'subscribed' => $subscribed,
            'entitlement' => $entitlement ? [
                'expires_date' => $entitlement['expires_date'],
                'product_identifier' => $entitlement['product_identifier'],
                'purchase_date' => $entitlement['purchase_date'],
            ] : null,
        ]);
    }

    public function checkoutUrl(Request $request): JsonResponse
    {
        $baseUrl = (string) config('services.revenuecat.purchase_link_url', '');
        $appUserId = (string) $request->user()->id;
        $url = rtrim($baseUrl, '/').'/'.urlencode($appUserId).'?skip_purchase_success=true';

        return response()->json(['checkout_url' => $url]);
    }

    public function manageUrl(Request $request): JsonResponse
    {
        $appUserId = (string) $request->user()->id;
        $pattern = (string) config('services.revenuecat.customer_center_url_pattern');
        $url = str_replace('{appUserId}', urlencode($appUserId), $pattern);

        return response()->json(['manage_url' => $url]);
    }
}
