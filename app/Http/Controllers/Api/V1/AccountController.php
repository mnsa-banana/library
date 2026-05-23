<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\EmailChangedNotice;
use App\Mail\EmailChangeMail;
use App\Models\EmailChange;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function updatePassword(Request $request): Response
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current password is incorrect.'),
            ]);
        }

        $currentTokenId = $request->user()->currentAccessToken()?->id;

        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->noContent();
    }

    public function requestEmailChange(Request $request): Response
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'new_email' => 'required|email|unique:users,email',
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current password is incorrect.'),
            ]);
        }

        EmailChange::where('user_id', $user->id)->whereNull('used_at')->delete();

        $plain = bin2hex(random_bytes(32));
        $change = EmailChange::create([
            'user_id' => $user->id,
            'new_email' => $data['new_email'],
            'token_hash' => Hash::make($plain),
            'expires_at' => now()->addMinutes(60),
        ]);

        Mail::to($data['new_email'])
            ->send(new EmailChangeMail(
                $data['new_email'],
                $plain,
                $change->id,
            ));

        return response()->noContent();
    }

    public function confirmEmailChange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'required|integer',
            'token' => 'required|string',
        ]);

        $change = EmailChange::whereKey($data['id'])
            ->whereNull('used_at')
            ->first();

        if (! $change || ! Hash::check($data['token'], $change->token_hash)) {
            throw ValidationException::withMessages(['token' => __('Invalid confirmation link.')]);
        }
        if ($change->isExpired()) {
            return response()->json(['message' => __('Confirmation link expired.')], 410);
        }

        $user = $change->user;
        $oldEmail = $user->email;

        try {
            DB::transaction(function () use ($user, $change) {
                $user->forceFill(['email' => $change->new_email])->save();
                $change->forceFill(['used_at' => now()])->save();
            });
        } catch (QueryException $e) {
            // users.email unique constraint — someone else grabbed this address first.
            return response()->json([
                'message' => __('That email is already taken.'),
            ], 409);
        }

        Mail::to($oldEmail)
            ->send(new EmailChangedNotice($user, $change->new_email));

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): Response
    {
        $data = $request->validate(['current_password' => 'required|string']);
        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current password is incorrect.'),
            ]);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }
}
