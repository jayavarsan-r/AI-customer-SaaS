<?php

namespace App\Services\Workflow\Actions;

use App\Models\Ticket;

interface ActionInterface
{
    /**
     * Execute the action against a ticket.
     *
     * @param  Ticket  $ticket   The ticket this workflow is triggered for
     * @param  array   $params   Action-specific parameters from workflow config
     * @return mixed             Result to be logged in WorkflowRun
     */
    public function execute(Ticket $ticket, array $params = []): mixed;
}
