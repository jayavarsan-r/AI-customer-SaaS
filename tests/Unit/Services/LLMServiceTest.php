<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\DTOs\LLMRequest;
use App\Services\LLM\DTOs\LLMResponse;
use App\Services\LLM\Exceptions\LLMRateLimitException;
use App\Services\LLM\LLMService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class LLMServiceTest extends TestCase
{
    use RefreshDatabase;

    private LLMService $service;
    private $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockProvider = Mockery::mock(LLMProviderInterface::class);
        $this->service      = new LLMService($this->mockProvider);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_complete_returns_llm_response(): void
    {
        $expectedResponse = $this->makeResponse('Hello! How can I help?');

        $this->mockProvider
            ->shouldReceive('complete')
            ->once()
            ->andReturn($expectedResponse);

        $request  = $this->makeRequest([['role' => 'user', 'content' => 'Hi']], useCache: false);
        $response = $this->service->complete($request);

        $this->assertEquals('Hello! How can I help?', $response->content);
        $this->assertEquals(100, $response->promptTokens);
        $this->assertEquals(50, $response->completionTokens);
    }

    public function test_caches_successful_response(): void
    {
        Cache::shouldReceive('store')->andReturnSelf();
        Cache::shouldReceive('get')->once()->andReturn(null);
        Cache::shouldReceive('put')->once();

        $this->mockProvider
            ->shouldReceive('complete')
            ->once()
            ->andReturn($this->makeResponse('Cached response'));

        $request = $this->makeRequest([['role' => 'user', 'content' => 'Same question']], useCache: true);
        $this->service->complete($request);
    }

    public function test_returns_cached_response_on_second_call(): void
    {
        $cachedData = [
            'content'           => 'Cached answer',
            'model'             => 'claude-sonnet-4-6',
            'prompt_tokens'     => 100,
            'completion_tokens' => 50,
            'total_tokens'      => 150,
            'latency_ms'        => 0,
            'stop_reason'       => 'end_turn',
        ];

        Cache::shouldReceive('store')->andReturnSelf();
        Cache::shouldReceive('get')->once()->andReturn($cachedData);

        // Provider should NOT be called when cache hits
        $this->mockProvider->shouldNotReceive('complete');

        $request  = $this->makeRequest([['role' => 'user', 'content' => 'Same question']], useCache: true);
        $response = $this->service->complete($request);

        $this->assertTrue($response->fromCache);
        $this->assertEquals('Cached answer', $response->content);
    }

    public function test_retries_on_rate_limit_exception(): void
    {
        $rateLimitException = new LLMRateLimitException('Rate limited', retryAfterSeconds: 1);
        $successResponse    = $this->makeResponse('Retry succeeded');

        $this->mockProvider
            ->shouldReceive('complete')
            ->twice()
            ->andThrow($rateLimitException)
            ->andReturn($successResponse);

        $request  = $this->makeRequest([['role' => 'user', 'content' => 'Hi']], useCache: false);
        $response = $this->service->complete($request);

        $this->assertEquals('Retry succeeded', $response->content);
    }

    public function test_throws_after_max_retries_exhausted(): void
    {
        $rateLimitException = new LLMRateLimitException('Always rate limited', retryAfterSeconds: 1);

        $this->mockProvider
            ->shouldReceive('complete')
            ->times(3)
            ->andThrow($rateLimitException);

        $this->expectException(LLMRateLimitException::class);

        $request = $this->makeRequest([['role' => 'user', 'content' => 'Hi']], useCache: false);
        $this->service->complete($request);
    }

    public function test_logs_usage_when_user_id_provided(): void
    {
        $user = User::factory()->create();

        $this->mockProvider
            ->shouldReceive('complete')
            ->once()
            ->andReturn($this->makeResponse('Test', promptTokens: 200, completionTokens: 100));

        $request = $this->makeRequest([['role' => 'user', 'content' => 'Test']], useCache: false);
        $this->service->complete($request, userId: $user->id, ticketId: null);

        $this->assertDatabaseHas('usage_logs', [
            'user_id'          => $user->id,
            'operation_type'   => 'chat',
            'prompt_tokens'    => 200,
            'completion_tokens' => 100,
            'total_tokens'     => 300,
        ]);
    }

    private function makeRequest(array $messages, bool $useCache = true): LLMRequest
    {
        return new LLMRequest(
            messages:      $messages,
            systemPrompt:  null,
            maxTokens:     512,
            temperature:   0.3,
            operationType: 'chat',
            useCache:      $useCache,
        );
    }

    private function makeResponse(string $content, int $promptTokens = 100, int $completionTokens = 50): LLMResponse
    {
        return new LLMResponse(
            content:          $content,
            model:            'claude-sonnet-4-6',
            promptTokens:     $promptTokens,
            completionTokens: $completionTokens,
            totalTokens:      $promptTokens + $completionTokens,
            latencyMs:        250.0,
        );
    }
}
