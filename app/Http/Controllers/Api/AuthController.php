<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        // 🔐 Optional: Revoke all tokens if multiple sessions disabled
        if (! config('sanctum.multiple_sessions', true)) {
            $user->tokens()->delete();
        }

        // 🎟️ Create a new token
        $plainToken = $user->createToken('auth-token')->plainTextToken;

        // Load relationships for response
        $user->load('roles.permissions');

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $plainToken,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    public function user(Request $request)
    {
        $user = $request->user()->load('roles.permissions');

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }
}
