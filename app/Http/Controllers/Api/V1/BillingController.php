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
        $secretKey = $request->attributes->get('revenuecat_secret_key', '');
        $subscriber = $rc->getSubscriber($appUserId, $secretKey);
        $entitlementId = $request->attributes->get('revenuecat_entitlement_id', config('brands.brands.' . config('brands.default') . '.entitlement_id'));

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
        $baseUrl = $request->attributes->get('revenuecat_purchase_link_url', config('brands.brands.' . config('brands.default') . '.purchase_link_url'));
        $appUserId = (string) $request->user()->id;
        $url = rtrim($baseUrl, '/') . '/' . urlencode($appUserId) . '?skip_purchase_success=true';

        return response()->json(['checkout_url' => $url]);
    }

    public function manageUrl(Request $request, \App\Support\BrandResolver $brands): JsonResponse
    {
        $brand = $brands->fromRequest($request);

        return response()->json([
            'manage_url' => $brand->customerCenterUrl((string) $request->user()->id),
        ]);
    }
}
