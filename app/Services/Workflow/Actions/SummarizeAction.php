<?php

namespace App\Services\Workflow\Actions;

use App\Jobs\SummarizeTicket;
use App\Models\Ticket;

class SummarizeAction implements ActionInterface
{
    public function execute(Ticket $ticket, array $params = []): mixed
    {
        SummarizeTicket::dispatch($ticket)->onQueue('default');

        return ['dispatched' => true, 'ticket_id' => $ticket->id];
    }
}
