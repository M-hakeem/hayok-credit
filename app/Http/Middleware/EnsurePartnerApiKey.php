<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePartnerApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Partner-Key');

        if (! $key || $key !== config('services.partner.api_key')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid or missing partner API key.',
            ], 401);
        }

        return $next($request);
    }
}
