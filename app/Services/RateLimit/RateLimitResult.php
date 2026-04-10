<?php

namespace App\Services\RateLimit;

final readonly class RateLimitResult
{
    private function __construct(
        public bool   $allowed,
        public int    $limit,
        public int    $current,
        public int    $retryAfter,
        public string $limitType,
    ) {}

    public static function allowed(int $limit, int $current, int $windowSeconds): self
    {
        return new self(true, $limit, $current, 0, '');
    }

    public static function exceeded(int $limit, int $current, int $retryAfter, string $limitType): self
    {
        return new self(false, $limit, $current, $retryAfter, $limitType);
    }

    public function remaining(): int
    {
        return max(0, $this->limit - $this->current);
    }
}
