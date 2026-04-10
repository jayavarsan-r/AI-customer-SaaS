<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\ApiRateLimitMiddleware;
use App\Http\Middleware\TokenQuotaMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api:        __DIR__ . '/../routes/api.php',
        apiPrefix:  'api',
        commands:   __DIR__ . '/../routes/console.php',
        health:     '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(append: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'api.rate_limit'  => ApiRateLimitMiddleware::class,
            'api.token_quota' => TokenQuotaMiddleware::class,
            'admin'           => AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthenticated.'], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error'  => 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Resource not found.'], 404);
            }
        });

        $exceptions->render(function (\App\Services\LLM\Exceptions\LLMRateLimitException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error'       => 'LLM provider rate limit exceeded.',
                    'retry_after' => $e->retryAfterSeconds,
                ], 503);
            }
        });
    })
    ->create();
