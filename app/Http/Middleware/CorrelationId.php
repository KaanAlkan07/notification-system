<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Correlation-ID', '');
        $id = preg_match('/\A[A-Za-z0-9._-]{1,100}\z/', $incoming) ? $incoming : (string) Str::uuid();
        $request->attributes->set('correlation_id', $id);
        Log::withContext(['correlation_id' => $id]);

        try {
            $response = $next($request);
            $response->headers->set('X-Correlation-ID', $id);

            return $response;
        } finally {
            Log::withoutContext();
        }
    }
}
