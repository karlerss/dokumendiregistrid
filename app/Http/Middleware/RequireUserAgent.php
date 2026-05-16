<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireUserAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $ua = trim((string)$request->userAgent());

        if ($ua === '') {
            return response()->json([
                'error' => 'Missing User-Agent header.',
                'message' => 'Please send a descriptive User-Agent header identifying your application and a way to contact you, e.g. "MyApp/1.0 (you@example.com)".',
            ], 400);
        }

        return $next($request);
    }
}
