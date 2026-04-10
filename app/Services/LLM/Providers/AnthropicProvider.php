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

class AnthropicProvider implements LLMProviderInterface
{
    private Client $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiVersion = '2023-06-01',
        private readonly string $baseUrl = 'https://api.anthropic.com',
        private readonly int    $timeout = 60,
    ) {
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => $this->timeout,
            'headers'  => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
                'content-type'      => 'application/json',
            ],
        ]);
    }

    public function complete(LLMRequest $request): LLMResponse
    {
        $startTime = microtime(true);

        $payload = $this->buildPayload($request);

        try {
            $response = $this->client->post('/v1/messages', ['json' => $payload]);
            $data     = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            $latencyMs = (microtime(true) - $startTime) * 1000;

            return new LLMResponse(
                content:           $data['content'][0]['text'] ?? '',
                model:             $data['model'],
                promptTokens:      $data['usage']['input_tokens'],
                completionTokens:  $data['usage']['output_tokens'],
                totalTokens:       $data['usage']['input_tokens'] + $data['usage']['output_tokens'],
                latencyMs:         $latencyMs,
                stopReason:        $data['stop_reason'] ?? 'end_turn',
            );
        } catch (ConnectException $e) {
            throw new LLMTimeoutException("Anthropic connection timeout: {$e->getMessage()}", 0, $e);
        } catch (RequestException $e) {
            $this->handleRequestException($e);
        }
    }

    public function stream(LLMRequest $request, callable $onChunk): LLMResponse
    {
        $startTime = microtime(true);

        $payload          = $this->buildPayload($request);
        $payload['stream'] = true;

        $fullContent     = '';
        $promptTokens    = 0;
        $completionTokens = 0;
        $model           = $request->model ?? $this->getDefaultModel();
        $stopReason      = 'end_turn';

        try {
            $response = $this->client->post('/v1/messages', [
                'json'   => $payload,
                'stream' => true,
            ]);

            $body = $response->getBody();

            while (!$body->eof()) {
                $line = trim($body->read(4096));
                if (empty($line)) {
                    continue;
                }

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

                    match ($event['type'] ?? '') {
                        'content_block_delta' => function () use ($event, &$fullContent, $onChunk) {
                            $chunk = $event['delta']['text'] ?? '';
                            $fullContent .= $chunk;
                            $onChunk($chunk);
                        },
                        'message_delta'       => function () use ($event, &$completionTokens, &$stopReason) {
                            $completionTokens = $event['usage']['output_tokens'] ?? 0;
                            $stopReason       = $event['delta']['stop_reason'] ?? 'end_turn';
                        },
                        'message_start'       => function () use ($event, &$promptTokens, &$model) {
                            $promptTokens = $event['message']['usage']['input_tokens'] ?? 0;
                            $model        = $event['message']['model'] ?? $model;
                        },
                        default => null,
                    };

                    // PHP doesn't support match with side effects cleanly; inline instead:
                    if (($event['type'] ?? '') === 'content_block_delta') {
                        $chunk        = $event['delta']['text'] ?? '';
                        $fullContent .= $chunk;
                        $onChunk($chunk);
                    } elseif (($event['type'] ?? '') === 'message_delta') {
                        $completionTokens = $event['usage']['output_tokens'] ?? 0;
                        $stopReason       = $event['delta']['stop_reason'] ?? 'end_turn';
                    } elseif (($event['type'] ?? '') === 'message_start') {
                        $promptTokens = $event['message']['usage']['input_tokens'] ?? 0;
                        $model        = $event['message']['model'] ?? $model;
                    }
                }
            }
        } catch (ConnectException $e) {
            throw new LLMTimeoutException("Anthropic stream timeout: {$e->getMessage()}", 0, $e);
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
        return config('llm.default_model', 'claude-sonnet-4-6');
    }

    public function countTokens(string $text): int
    {
        // Rough approximation: ~4 chars per token for English text
        // For production, use the Anthropic token counting API endpoint
        return (int) ceil(mb_strlen($text) / 4);
    }

    private function buildPayload(LLMRequest $request): array
    {
        $payload = [
            'model'      => $request->model ?? $this->getDefaultModel(),
            'max_tokens' => $request->maxTokens,
            'messages'   => $request->messages,
        ];

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->systemPrompt) {
            $payload['system'] = $request->systemPrompt;
        }

        return $payload;
    }

    private function handleRequestException(RequestException $e): never
    {
        $statusCode = $e->getResponse()?->getStatusCode();
        $body       = $e->getResponse()?->getBody()->getContents() ?? '';
        $error      = json_decode($body, true);
        $message    = $error['error']['message'] ?? $e->getMessage();

        Log::error('Anthropic API error', [
            'status'  => $statusCode,
            'message' => $message,
            'body'    => $body,
        ]);

        if ($statusCode === 429) {
            $retryAfter = (int) ($e->getResponse()?->getHeaderLine('retry-after') ?? 60);
            throw new LLMRateLimitException("Anthropic rate limit exceeded. Retry after {$retryAfter}s", $retryAfter);
        }

        if (in_array($statusCode, [500, 502, 503, 529])) {
            throw new LLMException("Anthropic server error ({$statusCode}): {$message}", $statusCode);
        }

        throw new LLMException("Anthropic API error ({$statusCode}): {$message}", $statusCode ?? 0);
    }
}
