<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthenticate
{
    public function __construct(protected JwtService $jwt) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $token = $this->extractToken($request);

        if (! $token) {
            if ($mode === 'optional') {
                return $next($request);
            }

            return response()->json(['message' => 'Not authorized, no token.'], 401);
        }

        try {
            $payload = $this->jwt->decode($token);
            $user = User::query()->find($payload->sub ?? null);
            if (! $user) {
                if ($mode === 'optional') {
                    return $next($request);
                }

                return response()->json(['message' => 'Not authorized, user not found.'], 401);
            }
            $request->attributes->set('authUser', $user);
            $request->setUserResolver(fn () => $user);
        } catch (\Throwable $e) {
            if ($mode === 'optional') {
                return $next($request);
            }

            return response()->json(['message' => 'Not authorized, token failed.'], 401);
        }

        return $next($request);
    }

    protected function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');
        if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
            return $m[1];
        }

        return $request->query('access_token') ?: $request->query('token');
    }
}
