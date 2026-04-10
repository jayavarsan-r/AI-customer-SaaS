<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessChatMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    private User   $user;
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user   = User::factory()->create(['plan' => 'pro', 'daily_token_quota' => 200_000]);
        $this->ticket = Ticket::factory()->create(['user_id' => $this->user->id]);

        Sanctum::actingAs($this->user);
        Queue::fake();
    }

    public function test_sending_message_queues_job(): void
    {
        $response = $this->postJson("/api/v1/tickets/{$this->ticket->uuid}/messages", [
            'content' => 'Hello, I need help with my billing.',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('meta.async', true)
            ->assertJsonStructure(['message' => ['id', 'role', 'content', 'status', 'created_at']]);

        Queue::assertPushed(ProcessChatMessage::class, function ($job) {
            return $job->message->content === 'Hello, I need help with my billing.'
                && $job->message->role === 'user';
        });
    }

    public function test_message_persisted_as_pending(): void
    {
        $this->postJson("/api/v1/tickets/{$this->ticket->uuid}/messages", [
            'content' => 'Test message.',
        ]);

        $this->assertDatabaseHas('messages', [
            'ticket_id' => $this->ticket->id,
            'role'      => 'user',
            'content'   => 'Test message.',
            'status'    => 'pending',
        ]);
    }

    public function test_second_message_reuses_existing_conversation(): void
    {
        $this->postJson("/api/v1/tickets/{$this->ticket->uuid}/messages", [
            'content' => 'First message.',
        ]);

        $this->postJson("/api/v1/tickets/{$this->ticket->uuid}/messages", [
            'content' => 'Second message.',
        ]);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_list_messages_returns_conversation_history(): void
    {
        $conversation = Conversation::factory()->create([
            'ticket_id' => $this->ticket->id,
            'user_id'   => $this->user->id,
        ]);

        Message::factory()->count(3)->create([
            'conversation_id' => $conversation->id,
            'ticket_id'       => $this->ticket->id,
            'user_id'         => $this->user->id,
            'status'          => 'completed',
        ]);

        $response = $this->getJson("/api/v1/tickets/{$this->ticket->uuid}/messages");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'role', 'content', 'status', 'created_at'],
                ],
                'meta' => ['total', 'conversation_id'],
            ]);
    }

    public function test_new_conversation_archives_old_one(): void
    {
        Conversation::factory()->create([
            'ticket_id' => $this->ticket->id,
            'user_id'   => $this->user->id,
            'status'    => 'active',
        ]);

        $this->postJson("/api/v1/tickets/{$this->ticket->uuid}/conversations")
            ->assertStatus(201);

        $this->assertDatabaseHas('conversations', ['ticket_id' => $this->ticket->id, 'status' => 'archived']);
        $this->assertDatabaseCount('conversations', 2);
    }

    public function test_user_with_exceeded_quota_blocked_from_chat(): void
    {
        $this->user->update(['daily_token_quota' => 0]); // Quota at 0

        $this->postJson("/api/v1/tickets/{$this->ticket->uuid}/messages", [
            'content' => 'Help!',
        ])->assertStatus(429);
    }

    public function test_cannot_message_other_users_ticket(): void
    {
        $otherUser   = User::factory()->create();
        $otherTicket = Ticket::factory()->create(['user_id' => $otherUser->id]);

        $this->postJson("/api/v1/tickets/{$otherTicket->uuid}/messages", [
            'content' => 'Unauthorized.',
        ])->assertStatus(404);
    }
}
