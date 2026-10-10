<?php

namespace App\Http\Middleware;

use App\Support\ClientIp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token gate for the compliance MCP endpoint. Every tool on that
 * server (reads included) exposes personal data, so the whole endpoint is
 * closed without ADMIN_TOKEN. Five wrong tokens from one IP lock it out for
 * fifteen minutes.
 */
class RequireAdminToken
{
    private const MAX_FAILURES = 5;
    private const LOCKOUT_SECONDS = 900;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'admin-token-failures:' . ClientIp::of($request);

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            return response()->json(['error' => 'Too many failed attempts.'], 429, [
                'Retry-After' => RateLimiter::availableIn($key),
            ]);
        }

        $expected = (string)config('compliance.admin_token');
        $given = (string)$request->bearerToken();

        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            if ($given !== '') {
                RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            }
            return response()->json(['error' => 'Unauthorized.'], 401, [
                'WWW-Authenticate' => 'Bearer',
            ]);
        }

        RateLimiter::clear($key);

        return $next($request);
    }
}
