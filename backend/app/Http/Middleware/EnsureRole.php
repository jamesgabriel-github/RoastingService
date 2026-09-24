<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // is_active is re-checked here (not just at login) so disabling an
        // admin mid-session revokes access immediately.
        if (! $user || ! in_array($user->role, $roles, true) || ! $user->is_active) {
            abort(403);
        }

        return $next($request);
    }
}
