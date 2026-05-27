<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('app.api_token');

        if (empty($token)) {
            return $next($request);
        }

        $provided = $request->bearerToken() ?? $request->header('X-Api-Token');

        if (!hash_equals($token, (string) $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
