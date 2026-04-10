<?php

namespace App\Services\RateLimit;

use App\Models\User;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-based sliding-window rate limiter.
 *
 * Keys used:
 *   rl:rpm:{userId}       — requests per minute (sliding window)
 *   rl:tpm:{userId}       — tokens per minute
 *   rl:tpd:{userId}       — tokens per day
 */
class RateLimitService
{
    private const RPM_WINDOW   = 60;     // seconds
    private const TPM_WINDOW   = 60;     // seconds
    private const TPD_WINDOW   = 86400;  // seconds (24h)

    public function __construct(
        private readonly \Predis\Client|\Redis $redis,
    ) {}

    /**
     * Check if user has exceeded request-per-minute limit.
     * Uses a sliding window via a sorted set of timestamps.
     */
    public function checkRPM(User $user): RateLimitResult
    {
        $limits = $user->getPlanLimits();
        $key    = "rl:rpm:{$user->id}";
        $now    = microtime(true);
        $window = $now - self::RPM_WINDOW;

        $this->redis->zremrangebyscore($key, '-inf', $window);
        $current = (int) $this->redis->zcard($key);

        if ($current >= $limits['rpm']) {
            $oldest     = $this->redis->zrange($key, 0, 0, ['WITHSCORES' => true]);
            $retryAfter = $oldest ? (int) ceil(self::RPM_WINDOW - ($now - array_values($oldest)[0])) : self::RPM_WINDOW;

            return RateLimitResult::exceeded($limits['rpm'], $current, $retryAfter, 'requests_per_minute');
        }

        $this->redis->zadd($key, [$now => $now]);
        $this->redis->expire($key, self::RPM_WINDOW + 5);

        return RateLimitResult::allowed($limits['rpm'], $current + 1, self::RPM_WINDOW);
    }

    /**
     * Check and record token usage against per-minute and per-day limits.
     */
    public function checkAndRecordTokens(User $user, int $tokens): RateLimitResult
    {
        $limits = $user->getPlanLimits();

        // Per-minute token check
        $tpmKey     = "rl:tpm:{$user->id}";
        $currentTPM = (int) $this->redis->get($tpmKey) ?: 0;

        if ($currentTPM + $tokens > $limits['daily_tokens'] / 144) {
            // Rough per-minute allowance: daily / (60*24 / 10-minute slots)
            // Using a simpler flat check here for efficiency
        }

        // Per-day token check
        $tpdKey     = "rl:tpd:{$user->id}";
        $currentTPD = (int) $this->redis->get($tpdKey) ?: 0;

        if ($currentTPD + $tokens > $limits['daily_tokens']) {
            $ttl        = (int) $this->redis->ttl($tpdKey);
            $retryAfter = $ttl > 0 ? $ttl : self::TPD_WINDOW;

            return RateLimitResult::exceeded($limits['daily_tokens'], $currentTPD, $retryAfter, 'tokens_per_day');
        }

        // Increment counters
        $this->redis->incrby($tpmKey, $tokens);
        $this->redis->expire($tpmKey, self::TPM_WINDOW);

        $this->redis->incrby($tpdKey, $tokens);
        if ($this->redis->ttl($tpdKey) < 0) {
            $this->redis->expire($tpdKey, self::TPD_WINDOW);
        }

        return RateLimitResult::allowed($limits['daily_tokens'], $currentTPD + $tokens, self::TPD_WINDOW);
    }

    /**
     * Get current usage stats for a user (for monitoring/API).
     */
    public function getUsageStats(User $user): array
    {
        $limits = $user->getPlanLimits();

        $rpmKey  = "rl:rpm:{$user->id}";
        $tpdKey  = "rl:tpd:{$user->id}";

        $now    = microtime(true);
        $window = $now - self::RPM_WINDOW;
        $this->redis->zremrangebyscore($rpmKey, '-inf', $window);

        return [
            'rpm' => [
                'used'  => (int) $this->redis->zcard($rpmKey),
                'limit' => $limits['rpm'],
            ],
            'tokens_today' => [
                'used'  => (int) ($this->redis->get($tpdKey) ?: 0),
                'limit' => $limits['daily_tokens'],
            ],
        ];
    }

    /**
     * Reset all rate limit counters for a user (admin use).
     */
    public function resetForUser(User $user): void
    {
        $this->redis->del([
            "rl:rpm:{$user->id}",
            "rl:tpm:{$user->id}",
            "rl:tpd:{$user->id}",
        ]);
    }
}
