<?php

namespace App\Http\Middleware;

use App\Services\RevenueCatService;
use Closure;
use Illuminate\Http\Request;

class EnsureSubscribed
{
    public function __construct(private RevenueCatService $rc) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $appUserId = (string) $user->id;
        $entitlementId = config('services.revenuecat.entitlement_id');
        $secretKey = (string) config('services.revenuecat.secret_key', '');

        if (! $this->rc->isSubscribed($appUserId, $entitlementId, $secretKey)) {
            return response()->json([
                'message' => 'Active subscription required.',
                'code' => 'subscription_required',
            ], 403);
        }

        return $next($request);
    }
}
