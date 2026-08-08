<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('authUser') ?? $request->user();
        if (! $user || ! $user->email_verified) {
            return response()->json([
                'message' => 'Please verify your email before continuing.',
                'needsVerification' => true,
                'email' => $user?->email,
            ], 403);
        }

        return $next($request);
    }
}
