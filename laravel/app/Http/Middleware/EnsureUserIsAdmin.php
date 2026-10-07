<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Reject any request from a guest or non-admin user. Responds with 404
     * so admin-only routes are not revealed to regular users.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isAdmin()) {
            abort(404);
        }

        return $next($request);
    }
}
