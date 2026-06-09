<?php

namespace App\Http\Controllers\Api\Fair;

use App\Domain\Users\Enums\UserType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        // Login runs before authentication, so SetLocale cannot key off the
        // request user. Honour the looked-up user's locale here so the error
        // messages still follow their language preference.
        if ($user && in_array($user->locale, ['en', 'zh_CN', 'pt_BR'], true)) {
            app()->setLocale($user->locale);
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('fair.auth.invalid_credentials')],
            ]);
        }

        if ($user->type !== UserType::INTERNAL || $user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => [__('fair.auth.not_allowed')],
            ]);
        }

        $token = $user->createToken(
            name: $credentials['device_name'],
            abilities: ['fair:register'],
        )->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
        ]);
    }
}
