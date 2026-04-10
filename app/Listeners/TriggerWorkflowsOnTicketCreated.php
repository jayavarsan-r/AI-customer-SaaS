<?php

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Listens for new tickets and fires matching workflows.
 * Runs async to keep the ticket creation API response fast.
 */
class TriggerWorkflowsOnTicketCreated implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(
        private readonly WorkflowEngine $engine,
    ) {}

    public function handle(TicketCreated $event): void
    {
        $this->engine->dispatchForEvent('ticket.created', $event->ticket);
    }
}
