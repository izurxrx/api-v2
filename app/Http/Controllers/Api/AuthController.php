<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:50|exists:users,username',
            'password' => 'required|string|max:255',
        ]);

        $key = 'login-attempts:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->errorResponse('Too many login attempts. Please try again later.', 429);
        }

        $user = User::where('username', $validated['username'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($key, 60); 
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($key);

        $token = $user->createToken('auth-token')->plainTextToken;

        return $this->successResponse([
            'user' => new UserResource($user),
            'token' => $token,
            'token_type' => 'Bearer',
        ], 'Login successful');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(null, 'Logout successful');
    }

    public function me(Request $request)
    {
        return $this->successResponse(new UserResource($request->user()), 'User profile retrieved successfully');
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string|max:255',
            'new_password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return $this->errorResponse('Current password is incorrect', 422);
        }

        if (Hash::check($validated['new_password'], $user->password)) {
            return $this->errorResponse('New password cannot be the same as the current password', 422);
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        return $this->successResponse(null, 'Password changed successfully');
    }
}