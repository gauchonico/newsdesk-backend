<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * The signed-in user's own profile — available to every role.
 */
class ProfileController extends Controller
{
    /**
     * GET /api/profile
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->resource($request)]);
    }

    /**
     * PATCH /api/profile
     * Body: { "name"?: string, "preferences"?: { "editor_font_size"?: int } }
     * Preferences are merged, so sending one key doesn't wipe the others.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                          => ['sometimes', 'string', 'max:255'],
            'preferences'                   => ['sometimes', 'array'],
            'preferences.editor_font_size'  => ['sometimes', 'integer', 'between:12,72'],
            'preferences.prompter_speed'    => ['sometimes', 'integer', 'between:1,20'],
            'preferences.prompter_font_size' => ['sometimes', 'integer', 'between:24,160'],
            'preferences.prompter_mirror'   => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }
        if (isset($data['preferences'])) {
            $user->preferences = array_merge($user->preferences ?? [], $data['preferences']);
        }
        $user->save();

        return response()->json(['data' => $this->resource($request)]);
    }

    /**
     * PUT /api/profile/password
     * Body: { "current_password", "password", "password_confirmation" }
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password updated.']);
    }

    private function resource(Request $request): UserResource
    {
        $user = $request->user()->load(['roles', 'platforms'])->loadCount(['stories' => fn ($q) => $q->where('status', '!=', 'draft')]);

        return new UserResource($user);
    }
}
