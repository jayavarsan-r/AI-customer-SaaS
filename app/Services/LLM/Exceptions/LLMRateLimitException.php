<?php

namespace App\Services\LLM\Exceptions;

class LLMRateLimitException extends LLMException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 60, ?\Throwable $previous = null)
    {
        parent::__construct($message, 429, $previous);
    }
}
