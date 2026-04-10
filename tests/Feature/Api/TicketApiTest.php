<?php

namespace Tests\Feature\Api;

use App\Events\TicketCreated;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'plan'              => 'pro',
            'daily_token_quota' => 200_000,
        ]);

        Sanctum::actingAs($this->user);

        // Prevent actual Redis calls in tests
        Queue::fake();
        Event::fake();
    }

    public function test_can_create_ticket(): void
    {
        $response = $this->postJson('/api/v1/tickets', [
            'subject'     => 'My payment is failing',
            'description' => 'I tried to pay but the card was declined.',
            'priority'    => 'high',
            'channel'     => 'api',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id', 'subject', 'status', 'priority', 'created_at',
            ])
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('priority', 'high');

        $this->assertDatabaseHas('tickets', [
            'user_id'  => $this->user->id,
            'subject'  => 'My payment is failing',
            'priority' => 'high',
        ]);

        Event::assertDispatched(TicketCreated::class, fn ($e) => $e->ticket->subject === 'My payment is failing');
    }

    public function test_creating_ticket_triggers_workflows(): void
    {
        $this->postJson('/api/v1/tickets', [
            'subject'  => 'Urgent: server is down',
            'priority' => 'urgent',
        ]);

        // Workflow dispatcher listener is async queued
        Event::assertDispatched(TicketCreated::class);
    }

    public function test_can_list_tickets_with_filters(): void
    {
        Ticket::factory()->count(3)->create(['user_id' => $this->user->id, 'status' => 'open']);
        Ticket::factory()->count(2)->create(['user_id' => $this->user->id, 'status' => 'resolved']);

        // List all
        $this->getJson('/api/v1/tickets')
            ->assertStatus(200)
            ->assertJsonCount(5, 'data');

        // Filter by status
        $this->getJson('/api/v1/tickets?status=open')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_cannot_access_another_users_ticket(): void
    {
        $otherUser   = User::factory()->create();
        $otherTicket = Ticket::factory()->create(['user_id' => $otherUser->id]);

        $this->getJson("/api/v1/tickets/{$otherTicket->uuid}")
            ->assertStatus(404);
    }

    public function test_can_update_ticket_status(): void
    {
        $ticket = Ticket::factory()->create(['user_id' => $this->user->id, 'status' => 'open']);

        $this->patchJson("/api/v1/tickets/{$ticket->uuid}", ['status' => 'resolved'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'resolved');

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'resolved']);
    }

    public function test_manual_summarize_queues_job(): void
    {
        $ticket = Ticket::factory()->create(['user_id' => $this->user->id]);

        $this->postJson("/api/v1/tickets/{$ticket->uuid}/summarize")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Summarization queued.');

        Queue::assertPushed(\App\Jobs\SummarizeTicket::class, fn ($job) => $job->ticket->id === $ticket->id);
    }

    public function test_manual_auto_tag_queues_job(): void
    {
        $ticket = Ticket::factory()->create(['user_id' => $this->user->id]);

        $this->postJson("/api/v1/tickets/{$ticket->uuid}/tag")
            ->assertStatus(200);

        Queue::assertPushed(\App\Jobs\AutoTagTicket::class);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->withoutMiddleware(\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class);

        // Make request without Sanctum acting as user
        $this->app['auth']->guard('sanctum')->forgetUser();

        $response = $this->getJson('/api/v1/tickets');
        $response->assertStatus(401);
    }

    public function test_validation_fails_on_missing_subject(): void
    {
        $this->postJson('/api/v1/tickets', ['priority' => 'high'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject']);
    }

    public function test_soft_deleted_ticket_not_visible(): void
    {
        $ticket = Ticket::factory()->create(['user_id' => $this->user->id]);
        $ticket->delete();

        $this->getJson("/api/v1/tickets/{$ticket->uuid}")->assertStatus(404);
    }
}
