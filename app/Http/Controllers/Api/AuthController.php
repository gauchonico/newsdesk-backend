<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /login
     *
     * Sanctum SPA cookie auth — no token is returned.
     * The browser stores the session cookie automatically.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => ['Your account has been suspended. Contact an administrator.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Authenticated.',
            'data'    => new UserResource($user->load(['roles', 'platforms'])),
        ]);
    }

    /**
     * POST /logout
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * GET /api/user
     *
     * Returns the authenticated user with their roles and all permissions
     * (used by the frontend auth store on page load).
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()->load(['roles', 'platforms'])));
    }
}