<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (strtolower((string) $user->role) !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Admin access is required.',
            ], 403);
        }

        return $next($request);
    }
}
