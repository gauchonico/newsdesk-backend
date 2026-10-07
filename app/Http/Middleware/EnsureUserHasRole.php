<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage in routes: ->middleware('role:admin') or ->middleware('role:admin,editor')
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole($roles)) {
            return response()->json(['message' => 'You do not have permission to perform this action.'], 403);
        }

        return $next($request);
    }
}
