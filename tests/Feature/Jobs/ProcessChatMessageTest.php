<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessChatMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\DTOs\LLMResponse;
use App\Services\LLM\LLMService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessChatMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_processes_message_and_creates_ai_response(): void
    {
        $user         = User::factory()->create();
        $ticket       = Ticket::factory()->create(['user_id' => $user->id]);
        $conversation = Conversation::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id]);
        $userMessage  = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'ticket_id'       => $ticket->id,
            'user_id'         => $user->id,
            'role'            => 'user',
            'content'         => 'What is my account balance?',
            'status'          => 'pending',
        ]);

        // Mock the LLM provider to return a predictable response
        $mockProvider = Mockery::mock(LLMProviderInterface::class);
        $mockProvider->shouldReceive('complete')->once()->andReturn(new LLMResponse(
            content:          'I can help you check your account balance.',
            model:            'claude-sonnet-4-6',
            promptTokens:     150,
            completionTokens: 30,
            totalTokens:      180,
            latencyMs:        320.0,
        ));

        $this->app->instance(LLMProviderInterface::class, $mockProvider);

        // Run the job
        $job = new ProcessChatMessage($userMessage, $conversation);
        $job->handle($this->app->make(LLMService::class));

        // User message should be completed
        $this->assertDatabaseHas('messages', [
            'id'     => $userMessage->id,
            'status' => 'completed',
        ]);

        // AI response should be created
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'content'         => 'I can help you check your account balance.',
            'status'          => 'completed',
            'total_tokens'    => 180,
        ]);

        // Conversation token totals updated
        $conversation->refresh();
        $this->assertEquals(150, $conversation->total_prompt_tokens);
        $this->assertEquals(30, $conversation->total_completion_tokens);

        // Usage logged
        $this->assertDatabaseHas('usage_logs', [
            'user_id'       => $user->id,
            'total_tokens'  => 180,
            'was_successful' => 1,
        ]);
    }

    public function test_marks_message_failed_on_exception(): void
    {
        $user         = User::factory()->create();
        $ticket       = Ticket::factory()->create(['user_id' => $user->id]);
        $conversation = Conversation::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id]);
        $userMessage  = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'ticket_id'       => $ticket->id,
            'user_id'         => $user->id,
            'role'            => 'user',
            'content'         => 'Test message',
            'status'          => 'pending',
        ]);

        $mockProvider = Mockery::mock(LLMProviderInterface::class);
        $mockProvider->shouldReceive('complete')->andThrow(new \RuntimeException('API connection refused'));

        $this->app->instance(LLMProviderInterface::class, $mockProvider);

        $this->expectException(\RuntimeException::class);

        $job = new ProcessChatMessage($userMessage, $conversation);
        $job->handle($this->app->make(LLMService::class));

        $this->assertDatabaseHas('messages', [
            'id'     => $userMessage->id,
            'status' => 'failed',
        ]);
    }
}
