<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request and verify role permissions.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your user account has been deactivated.',
            ], 403);
        }

        if (! empty($roles)) {
            $user->loadMissing('role');

            if (! $user->hasRole($roles)) {
                return response()->json([
                    'message' => 'You do not have permission to perform this action.',
                ], 403);
            }
        }

        return $next($request);
    }
}
