<?php

namespace App\Services\LLM\Providers;

use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\DTOs\LLMRequest;
use App\Services\LLM\DTOs\LLMResponse;
use App\Services\LLM\Exceptions\LLMException;
use App\Services\LLM\Exceptions\LLMRateLimitException;
use App\Services\LLM\Exceptions\LLMTimeoutException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class OpenAIProvider implements LLMProviderInterface
{
    private Client $client;

    public function __construct(
        private readonly string  $apiKey,
        private readonly ?string $organization = null,
        private readonly int     $timeout = 60,
    ) {
        $headers = [
            'Authorization' => "Bearer {$this->apiKey}",
            'content-type'  => 'application/json',
        ];

        if ($this->organization) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        $this->client = new Client([
            'base_uri' => 'https://api.openai.com',
            'timeout'  => $this->timeout,
            'headers'  => $headers,
        ]);
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $startTime = microtime(true);
        $payload   = $this->buildPayload($request);

        try {
            $response = $this->client->post('/v1/chat/completions', ['json' => $payload]);
            $data     = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            $latencyMs = (microtime(true) - $startTime) * 1000;

            return new LLMResponse(
                content:          $data['choices'][0]['message']['content'] ?? '',
                model:            $data['model'],
                promptTokens:     $data['usage']['prompt_tokens'],
                completionTokens: $data['usage']['completion_tokens'],
                totalTokens:      $data['usage']['total_tokens'],
                latencyMs:        $latencyMs,
                stopReason:       $data['choices'][0]['finish_reason'] ?? 'stop',
            );
        } catch (ConnectException $e) {
            throw new LLMTimeoutException("OpenAI connection timeout: {$e->getMessage()}", 0, $e);
        } catch (RequestException $e) {
            $this->handleRequestException($e);
        }
    }

    public function stream(LLMRequest $request, callable $onChunk): LLMResponse
    {
        $startTime        = microtime(true);
        $payload          = $this->buildPayload($request);
        $payload['stream'] = true;

        $fullContent     = '';
        $promptTokens    = 0;
        $completionTokens = 0;
        $model           = $request->model ?? $this->getDefaultModel();
        $stopReason      = 'stop';

        try {
            $response = $this->client->post('/v1/chat/completions', [
                'json'   => $payload,
                'stream' => true,
            ]);

            $body = $response->getBody();

            while (!$body->eof()) {
                $line = trim($body->read(4096));
                foreach (explode("\n", $line) as $rawLine) {
                    $rawLine = trim($rawLine);
                    if (!str_starts_with($rawLine, 'data: ')) {
                        continue;
                    }

                    $json = substr($rawLine, 6);
                    if ($json === '[DONE]') {
                        break;
                    }

                    $event = json_decode($json, true);
                    if (!$event) {
                        continue;
                    }

                    $chunk = $event['choices'][0]['delta']['content'] ?? '';
                    if ($chunk) {
                        $fullContent .= $chunk;
                        $onChunk($chunk);
                    }

                    if (isset($event['choices'][0]['finish_reason'])) {
                        $stopReason = $event['choices'][0]['finish_reason'];
                    }

                    if (isset($event['usage'])) {
                        $promptTokens     = $event['usage']['prompt_tokens'] ?? 0;
                        $completionTokens = $event['usage']['completion_tokens'] ?? 0;
                    }

                    if (isset($event['model'])) {
                        $model = $event['model'];
                    }
                }
            }
        } catch (ConnectException $e) {
            throw new LLMTimeoutException("OpenAI stream timeout: {$e->getMessage()}", 0, $e);
        } catch (RequestException $e) {
            $this->handleRequestException($e);
        }

        $latencyMs = (microtime(true) - $startTime) * 1000;

        return new LLMResponse(
            content:          $fullContent,
            model:            $model,
            promptTokens:     $promptTokens,
            completionTokens: $completionTokens,
            totalTokens:      $promptTokens + $completionTokens,
            latencyMs:        $latencyMs,
            stopReason:       $stopReason,
        );
    }

    public function getDefaultModel(): string
    {
        return 'gpt-4o';
    }

    public function countTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    private function buildPayload(LLMRequest $request): array
    {
        $messages = $request->messages;

        if ($request->systemPrompt) {
            array_unshift($messages, ['role' => 'system', 'content' => $request->systemPrompt]);
        }

        return [
            'model'       => $request->model ?? $this->getDefaultModel(),
            'messages'    => $messages,
            'max_tokens'  => $request->maxTokens,
            'temperature' => $request->temperature,
        ];
    }

    private function handleRequestException(RequestException $e): never
    {
        $statusCode = $e->getResponse()?->getStatusCode();
        $body       = $e->getResponse()?->getBody()->getContents() ?? '';
        $error      = json_decode($body, true);
        $message    = $error['error']['message'] ?? $e->getMessage();

        Log::error('OpenAI API error', ['status' => $statusCode, 'message' => $message]);

        if ($statusCode === 429) {
            $retryAfter = (int) ($e->getResponse()?->getHeaderLine('retry-after') ?? 60);
            throw new LLMRateLimitException("OpenAI rate limit exceeded. Retry after {$retryAfter}s", $retryAfter);
        }

        throw new LLMException("OpenAI API error ({$statusCode}): {$message}", $statusCode ?? 0);
    }
}
