<?php

use App\Http\Middleware\ApiToken;
use App\Http\Middleware\CorrelationId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', apiPrefix: '')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['api.token' => ApiToken::class]);
        $middleware->prepend(CorrelationId::class);
        $middleware->trimStrings(except: ['content', 'notifications.*.content']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->report(function (QueryException $error): bool {
            Log::error('database.query_failed', [
                'correlation_id' => request()->attributes->get('correlation_id'),
                'sqlstate' => $error->getCode(),
            ]);

            return false;
        });
        $exceptions->respond(function (Response $response, Throwable $error, Request $request) {
            if ($request->attributes->has('correlation_id')) {
                $response->headers->set('X-Correlation-ID', $request->attributes->get('correlation_id'));
            }

            return $response;
        });
    })
    ->create();
