<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RevenueCatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|confirmed|min:8',
        ]);

        $user = User::create($validated);
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->only('id', 'name', 'email'),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->only('id', 'name', 'email'),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request, RevenueCatService $rc): JsonResponse
    {
        $user = $request->user();
        $entitlementId = $request->attributes->get('revenuecat_entitlement_id', config('brands.brands.' . config('brands.default') . '.entitlement_id'));
        $secretKey = $request->attributes->get('revenuecat_secret_key', '');
        $subscribed = $rc->isSubscribed((string) $user->id, $entitlementId, $secretKey);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'subscribed' => $subscribed,
        ]);
    }
}
