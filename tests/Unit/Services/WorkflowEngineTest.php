<?php

namespace Tests\Unit\Services;

use App\Jobs\AutoTagTicket;
use App\Jobs\SummarizeTicket;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\Workflow\Actions\EmailAction;
use App\Services\Workflow\Actions\SummarizeAction;
use App\Services\Workflow\Actions\TagAction;
use App\Services\Workflow\Actions\UpdateTicketAction;
use App\Services\Workflow\Actions\WebhookAction;
use App\Services\Workflow\Conditions\ConditionEvaluator;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowEngine $engine;
    private User           $user;
    private Ticket         $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->engine = new WorkflowEngine(
            conditionEvaluator: new ConditionEvaluator(),
            summarizeAction:    new SummarizeAction(),
            tagAction:          new TagAction(),
            emailAction:        new EmailAction(),
            webhookAction:      new WebhookAction(),
            updateTicketAction: new UpdateTicketAction(),
        );

        $this->user   = User::factory()->create();
        $this->ticket = Ticket::factory()->create(['user_id' => $this->user->id, 'priority' => 'urgent', 'status' => 'open']);
    }

    public function test_executes_summarize_action(): void
    {
        $workflow = Workflow::factory()->create([
            'user_id' => $this->user->id,
            'trigger' => ['event' => 'ticket.created', 'conditions' => []],
            'actions' => [['type' => 'summarize']],
        ]);

        $run = WorkflowRun::factory()->create([
            'workflow_id'      => $workflow->id,
            'user_id'          => $this->user->id,
            'triggerable_type' => Ticket::class,
            'triggerable_id'   => $this->ticket->id,
        ]);

        $this->engine->execute($workflow, $this->ticket, $run);

        Queue::assertPushed(SummarizeTicket::class, fn ($job) => $job->ticket->id === $this->ticket->id);
    }

    public function test_executes_tag_action(): void
    {
        $workflow = Workflow::factory()->create([
            'user_id' => $this->user->id,
            'trigger' => ['event' => 'ticket.created'],
            'actions' => [['type' => 'tag']],
        ]);

        $run = WorkflowRun::factory()->create([
            'workflow_id'      => $workflow->id,
            'user_id'          => $this->user->id,
            'triggerable_type' => Ticket::class,
            'triggerable_id'   => $this->ticket->id,
        ]);

        $this->engine->execute($workflow, $this->ticket, $run);

        Queue::assertPushed(AutoTagTicket::class);
    }

    public function test_conditions_prevent_execution_when_not_met(): void
    {
        $workflow = Workflow::factory()->create([
            'user_id' => $this->user->id,
            'trigger' => [
                'event'      => 'ticket.created',
                'conditions' => [
                    ['field' => 'priority', 'operator' => 'equals', 'value' => 'low'], // Won't match urgent
                ],
            ],
            'actions' => [['type' => 'summarize']],
        ]);

        $run = WorkflowRun::factory()->create([
            'workflow_id'      => $workflow->id,
            'user_id'          => $this->user->id,
            'triggerable_type' => Ticket::class,
            'triggerable_id'   => $this->ticket->id,
        ]);

        $this->engine->execute($workflow, $this->ticket, $run);

        Queue::assertNotPushed(SummarizeTicket::class);
    }

    public function test_unknown_action_is_logged_not_fatal(): void
    {
        $workflow = Workflow::factory()->create([
            'user_id' => $this->user->id,
            'trigger' => ['event' => 'ticket.created'],
            'actions' => [
                ['type' => 'unknown_action_xyz'],
                ['type' => 'tag'], // Should still run
            ],
        ]);

        $run = WorkflowRun::factory()->create([
            'workflow_id'      => $workflow->id,
            'user_id'          => $this->user->id,
            'triggerable_type' => Ticket::class,
            'triggerable_id'   => $this->ticket->id,
        ]);

        // Should not throw — bad actions are logged, execution continues
        $this->engine->execute($workflow, $this->ticket, $run);

        Queue::assertPushed(AutoTagTicket::class);
    }

    public function test_update_ticket_action_modifies_ticket(): void
    {
        $workflow = Workflow::factory()->create([
            'user_id' => $this->user->id,
            'trigger' => ['event' => 'ticket.created'],
            'actions' => [
                ['type' => 'update_ticket', 'params' => ['status' => 'in_progress']],
            ],
        ]);

        $run = WorkflowRun::factory()->create([
            'workflow_id'      => $workflow->id,
            'user_id'          => $this->user->id,
            'triggerable_type' => Ticket::class,
            'triggerable_id'   => $this->ticket->id,
        ]);

        $this->engine->execute($workflow, $this->ticket, $run);

        $this->assertDatabaseHas('tickets', ['id' => $this->ticket->id, 'status' => 'in_progress']);
    }
}
