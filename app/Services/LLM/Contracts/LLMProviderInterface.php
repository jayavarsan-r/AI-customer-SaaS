<?php

namespace App\Services\LLM\Contracts;

use App\Services\LLM\DTOs\LLMRequest;
use App\Services\LLM\DTOs\LLMResponse;

interface LLMProviderInterface
{
    public function complete(LLMRequest $request): LLMResponse;

    public function stream(LLMRequest $request, callable $onChunk): LLMResponse;

    public function getDefaultModel(): string;

    public function countTokens(string $text): int;
}
