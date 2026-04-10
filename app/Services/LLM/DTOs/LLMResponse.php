<?php

namespace App\Services\LLM\DTOs;

final readonly class LLMResponse
{
    public function __construct(
        public string $content,
        public string $model,
        public int    $promptTokens,
        public int    $completionTokens,
        public int    $totalTokens,
        public float  $latencyMs,
        public bool   $fromCache = false,
        public string $stopReason = 'end_turn',
    ) {}

    public function toArray(): array
    {
        return [
            'content'           => $this->content,
            'model'             => $this->model,
            'prompt_tokens'     => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens'      => $this->totalTokens,
            'latency_ms'        => $this->latencyMs,
            'from_cache'        => $this->fromCache,
            'stop_reason'       => $this->stopReason,
        ];
    }
}
