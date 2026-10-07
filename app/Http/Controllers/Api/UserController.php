<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * GET /api/users
     * Supports: ?search=, ?role=, ?reporter_kind=, ?active=, ?page=, ?per_page= (max 100)
     * Also returns a `summary` (active count, stories filed) across every matching user, not just the page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $query = User::query()
            ->search($request->input('search'))
            ->filterRole($request->input('role'))           // custom scope, not Spatie
            ->when($request->input('reporter_kind'), function ($q, $kind) {
                $q->where('reporter_kind', $kind);
            })
            ->when($request->has('active') && $request->input('active') !== '', function ($q) use ($request) {
                $q->where('active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
            });

        $summary = [
            'active'  => (clone $query)->where('active', true)->count(),
            'stories' => \App\Models\Story::where('status', '!=', 'draft')->whereIn('user_id', (clone $query)->select('users.id'))->count(),
        ];

        $users = $query->with(['roles', 'platforms'])
            ->withCount(['stories' => fn ($q) => $q->where('status', '!=', 'draft')])
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return UserResource::collection($users)->additional(['summary' => $summary]);
    }

    /**
     * GET /api/users/{user}
     */
    public function show(User $user): JsonResponse
    {
        $user->load(['roles', 'platforms']);
        return response()->json(['data' => new UserResource($user)]);
    }

    /**
     * POST /api/users/invite
     *
     * activate_now=false  → random unguessable password hash, invite email queued
     * activate_now=true   → readable temp password, email_verified_at set, ready to log in
     */
    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'unique:users,email'],
            'roles'         => ['required', 'array', 'min:1'],
            'roles.*'       => [Rule::in(['admin', 'editor', 'reporter'])],
            'reporter_kind' => ['nullable', Rule::in(['in-house', 'community'])],
            'activate_now'  => ['boolean'],
            'platform_ids'  => ['nullable', 'array'],
            'platform_ids.*'=> ['integer', 'exists:platforms,id'],
        ]);

        $activateNow       = (bool) ($data['activate_now'] ?? false);
        $temporaryPassword = null;

        if ($activateNow) {
            $temporaryPassword = Str::lower(Str::random(5)) . '-' . Str::lower(Str::random(5)) . '-' . rand(1000, 9999);
            $passwordHash      = Hash::make($temporaryPassword);
            $verifiedAt        = now();
        } else {
            $passwordHash = Hash::make(Str::random(32));
            $verifiedAt   = null;
        }

        $user = User::create([
            'name'              => $data['name'],
            'email'             => $data['email'],
            'password'          => $passwordHash,
            'reporter_kind'     => $data['reporter_kind'] ?? null,
            'active'            => true,
            'email_verified_at' => $verifiedAt,
        ]);

        // Uses our custom syncRoles() on the User model — no Spatie dependency
        $user->syncRoles($data['roles']);
        $user->platforms()->sync($data['platform_ids'] ?? []);

        // Uncomment once UserInvited notification is set up:
        // if (! $activateNow) { $user->notify(new \App\Notifications\UserInvited()); }

        $response = ['data' => new UserResource($user->load(['roles', 'platforms']))];

        if ($activateNow) {
            $response['temporary_password'] = $temporaryPassword;
        }

        return response()->json($response, 201);
    }

    /**
     * PUT /api/users/{user}/roles
     * Body: { "roles": ["editor", "reporter"] }
     */
    public function syncRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles'   => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(['admin', 'editor', 'reporter'])],
        ]);

        $user->syncRoles($data['roles']);

        return response()->json(['data' => new UserResource($user->fresh()->load(['roles', 'platforms']))]);
    }

    /**
     * PUT /api/users/{user}/platforms
     * Body: { "platform_ids": [1, 3] }  — replaces the user's platform assignments
     */
    public function syncPlatforms(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'platform_ids'   => ['present', 'array'],
            'platform_ids.*' => ['integer', 'exists:platforms,id'],
        ]);

        $user->platforms()->sync($data['platform_ids']);

        return response()->json(['data' => new UserResource($user->fresh()->load(['roles', 'platforms']))]);
    }

    /**
     * PATCH /api/users/{user}/activate
     * Body: { "active": true|false }
     */
    public function activate(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id && ! $request->boolean('active')) {
            return response()->json(['message' => 'You cannot suspend your own account.'], 422);
        }

        $data = $request->validate(['active' => ['required', 'boolean']]);

        $user->update(['active' => $data['active']]);

        return response()->json(['data' => new UserResource($user->fresh()->load(['roles', 'platforms']))]);
    }

    /**
     * PATCH /api/users/{user}
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:255'],
            'reporter_kind' => ['sometimes', 'nullable', Rule::in(['in-house', 'community'])],
            'active'        => ['sometimes', 'boolean'],
        ]);

        if (isset($data['active']) && ! $data['active'] && $request->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot suspend your own account.'], 422);
        }

        $user->update($data);

        return response()->json(['data' => new UserResource($user->fresh()->load(['roles', 'platforms']))]);
    }

    /**
     * DELETE /api/users/{user}
     */
    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json(['message' => 'User removed.']);
    }
}