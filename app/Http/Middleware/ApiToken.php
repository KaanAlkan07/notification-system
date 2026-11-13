<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('notifications.api_token');
        if ($token === '' && app()->environment('local', 'testing')) {
            return $next($request);
        }

        abort_unless($token !== '' && hash_equals($token, $request->bearerToken() ?? ''), 401, 'Invalid or missing API token.', [
            'WWW-Authenticate' => 'Bearer',
        ]);

        return $next($request);
    }
}
