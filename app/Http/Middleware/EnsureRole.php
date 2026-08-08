<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (count($roles) === 1 && str_contains($roles[0], ',')) {
            $roles = array_map('trim', explode(',', $roles[0]));
        }

        $user = $request->attributes->get('authUser') ?? $request->user();
        if (! $user || ! in_array($user->role, $roles, true)) {
            return response()->json([
                'message' => 'Forbidden.',
                'requiredRoles' => $roles,
            ], 403);
        }

        return $next($request);
    }
}
