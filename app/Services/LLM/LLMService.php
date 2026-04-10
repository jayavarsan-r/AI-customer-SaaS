<?php

namespace App\Services\LLM;

use App\Models\UsageLog;
use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\DTOs\LLMRequest;
use App\Services\LLM\DTOs\LLMResponse;
use App\Services\LLM\Exceptions\LLMRateLimitException;
use App\Services\LLM\Exceptions\LLMTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Central LLM orchestration service.
 *
 * Responsibilities:
 *  - Cache-aware completions (read/write)
 *  - Exponential-backoff retries
 *  - Usage logging to DB
 *  - Streaming passthrough
 */
class LLMService
{
    private const MAX_RETRIES = 3;
    private const BASE_DELAY_MS = 1000;

    public function __construct(
        private readonly LLMProviderInterface $provider,
    ) {}

    /**
     * Complete a chat request with caching + retries + usage logging.
     */
    public function complete(LLMRequest $request, ?int $userId = null, ?int $ticketId = null, ?int $messageId = null): LLMResponse
    {
        // 1. Check cache first
        if ($request->useCache && config('llm.cache_enabled', true)) {
            $cached = $this->getFromCache($request);
            if ($cached !== null) {
                $this->logUsage($cached, $request, $userId, $ticketId, $messageId, fromCache: true);
                return $cached;
            }
        }

        // 2. Execute with retry logic
        $response = $this->executeWithRetry($request);

        // 3. Cache successful response
        if ($request->useCache && config('llm.cache_enabled', true)) {
            $this->storeInCache($request, $response);
        }

        // 4. Log usage
        $this->logUsage($response, $request, $userId, $ticketId, $messageId, fromCache: false);

        return $response;
    }

    /**
     * Stream a response chunk by chunk. Bypasses cache (streaming can't be cached).
     */
    public function stream(LLMRequest $request, callable $onChunk, ?int $userId = null, ?int $ticketId = null, ?int $messageId = null): LLMResponse
    {
        $response = $this->executeStreamWithRetry($request, $onChunk);
        $this->logUsage($response, $request, $userId, $ticketId, $messageId, fromCache: false);
        return $response;
    }

    /**
     * Count approximate tokens in a string.
     */
    public function countTokens(string $text): int
    {
        return $this->provider->countTokens($text);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function executeWithRetry(LLMRequest $request): LLMResponse
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                return $this->provider->complete($request);
            } catch (LLMRateLimitException $e) {
                $lastException = $e;
                $delay = $e->retryAfterSeconds * 1000; // convert to ms
                Log::warning("LLM rate limit hit, retrying after {$e->retryAfterSeconds}s", ['attempt' => $attempt + 1]);
                $this->sleep($delay);
            } catch (LLMTimeoutException $e) {
                $lastException = $e;
                $delay = self::BASE_DELAY_MS * (2 ** $attempt);
                Log::warning("LLM timeout, retrying with exponential backoff", ['attempt' => $attempt + 1, 'delay_ms' => $delay]);
                $this->sleep($delay);
            } catch (\Throwable $e) {
                // Non-retryable errors (auth, bad request, etc.)
                Log::error("LLM non-retryable error: {$e->getMessage()}", ['class' => $e::class]);
                throw $e;
            }
            $attempt++;
        }

        throw $lastException;
    }

    private function executeStreamWithRetry(LLMRequest $request, callable $onChunk): LLMResponse
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                return $this->provider->stream($request, $onChunk);
            } catch (LLMRateLimitException $e) {
                $lastException = $e;
                $this->sleep($e->retryAfterSeconds * 1000);
            } catch (LLMTimeoutException $e) {
                $lastException = $e;
                $this->sleep(self::BASE_DELAY_MS * (2 ** $attempt));
            } catch (\Throwable $e) {
                throw $e;
            }
            $attempt++;
        }

        throw $lastException;
    }

    private function getFromCache(LLMRequest $request): ?LLMResponse
    {
        $cached = Cache::store('redis')->get($request->getCacheKey());

        if ($cached === null) {
            return null;
        }

        Log::debug('LLM cache hit', ['key' => $request->getCacheKey()]);

        return new LLMResponse(
            content:          $cached['content'],
            model:            $cached['model'],
            promptTokens:     $cached['prompt_tokens'],
            completionTokens: $cached['completion_tokens'],
            totalTokens:      $cached['total_tokens'],
            latencyMs:        0,
            fromCache:        true,
            stopReason:       $cached['stop_reason'] ?? 'end_turn',
        );
    }

    private function storeInCache(LLMRequest $request, LLMResponse $response): void
    {
        $ttl = config('llm.cache_ttl', 3600);

        Cache::store('redis')->put(
            $request->getCacheKey(),
            $response->toArray(),
            $ttl
        );
    }

    private function logUsage(LLMResponse $response, LLMRequest $request, ?int $userId, ?int $ticketId, ?int $messageId, bool $fromCache): void
    {
        if ($userId === null) {
            return;
        }

        try {
            $cost = UsageLog::estimateCost($response->model, $response->promptTokens, $response->completionTokens);

            UsageLog::create([
                'user_id'           => $userId,
                'ticket_id'         => $ticketId,
                'message_id'        => $messageId,
                'model_used'        => $response->model,
                'operation_type'    => $request->operationType ?? 'chat',
                'prompt_tokens'     => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'total_tokens'      => $response->totalTokens,
                'estimated_cost_usd' => $cost,
                'latency_ms'        => $response->latencyMs,
                'was_cached'        => $fromCache || $response->fromCache,
                'was_successful'    => true,
                'usage_date'        => today(),
            ]);
        } catch (\Throwable $e) {
            // Never let logging break the main flow
            Log::error("Failed to log LLM usage: {$e->getMessage()}");
        }
    }

    private function sleep(int $ms): void
    {
        usleep($ms * 1000);
    }
}
