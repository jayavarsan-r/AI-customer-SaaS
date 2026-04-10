<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks requests from users who have exceeded their daily token quota.
 * Only applies to chat/LLM endpoints (not admin or listing endpoints).
 */
class TokenQuotaMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if ($user->hasExceededDailyTokenQuota()) {
            $remaining = $user->getRemainingDailyTokens();
            $resetAt   = now()->endOfDay();

            return response()->json([
                'error'            => 'Daily token quota exceeded.',
                'quota_limit'      => $user->daily_token_quota,
                'quota_remaining'  => 0,
                'reset_at'         => $resetAt->toIso8601String(),
                'upgrade_url'      => url('/api/v1/billing/upgrade'),
            ], 429);
        }

        $response = $next($request);

        // Attach quota headers
        $response->headers->set('X-Token-Quota-Limit', $user->daily_token_quota);
        $response->headers->set('X-Token-Quota-Remaining', $user->getRemainingDailyTokens());

        return $response;
    }
}
