<?php

namespace App\Services\LLM\DTOs;

final readonly class LLMRequest
{
    /**
     * @param  array<array{role: string, content: string}>  $messages
     */
    public function __construct(
        public array   $messages,
        public ?string $systemPrompt = null,
        public ?string $model = null,
        public int     $maxTokens = 2048,
        public float   $temperature = 0.3,
        public ?string $operationType = null,
        public bool    $useCache = true,
        public ?string $userId = null,
    ) {}

    public function getCacheKey(): string
    {
        $payload = [
            'messages'     => $this->messages,
            'systemPrompt' => $this->systemPrompt,
            'model'        => $this->model,
            'maxTokens'    => $this->maxTokens,
            'temperature'  => $this->temperature,
        ];
        return 'llm_cache:' . hash('sha256', json_encode($payload));
    }
}
