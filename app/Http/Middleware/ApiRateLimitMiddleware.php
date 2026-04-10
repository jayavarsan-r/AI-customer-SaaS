<?php

namespace App\Http\Middleware;

use App\Services\RateLimit\RateLimitService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies sliding-window RPM rate limiting per user.
 * Applied to all authenticated API routes.
 *
 * Returns standard rate limit headers on every response.
 */
class ApiRateLimitMiddleware
{
    public function __construct(
        private readonly RateLimitService $rateLimiter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request); // Unauthenticated requests handled by auth middleware
        }

        $result = $this->rateLimiter->checkRPM($user);

        if (!$result->allowed) {
            return response()->json([
                'error'       => 'Too many requests.',
                'limit_type'  => $result->limitType,
                'limit'       => $result->limit,
                'retry_after' => $result->retryAfter,
            ], 429, $this->buildHeaders($result->limit, $result->current, $result->retryAfter));
        }

        $response = $next($request);

        // Attach rate limit info headers to every response
        $response->headers->set('X-RateLimit-Limit', $result->limit);
        $response->headers->set('X-RateLimit-Remaining', $result->remaining());
        $response->headers->set('X-RateLimit-Reset', now()->addSeconds(60)->timestamp);

        return $response;
    }

    private function buildHeaders(int $limit, int $current, int $retryAfter): array
    {
        return [
            'X-RateLimit-Limit'     => $limit,
            'X-RateLimit-Remaining' => 0,
            'Retry-After'           => $retryAfter,
        ];
    }
}
