<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use App\Support\BrandResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    public function __construct(private BrandResolver $brands) {}

    public function forgot(Request $request): Response
    {
        $data = $request->validate(['email' => 'required|email']);

        $user = User::where('email', $data['email'])->first();
        if ($user) {
            $brand = $this->brands->fromRequest($request);
            $token = Password::createToken($user);
            Mail::to($user->email)->send(new ResetPasswordMail($user, $token, $brand));
        }

        return response()->noContent();
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __('This password reset link is invalid or has expired.'),
            ], 422);
        }

        $user = User::where('email', $data['email'])->firstOrFail();
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => $user->only('id', 'name', 'email'),
            'token' => $token,
        ]);
    }
}
