<?php

namespace App\Services\Workflow\Actions;

use App\Jobs\AutoTagTicket;
use App\Models\Ticket;

class TagAction implements ActionInterface
{
    public function execute(Ticket $ticket, array $params = []): mixed
    {
        AutoTagTicket::dispatch($ticket)->onQueue('default');

        return ['dispatched' => true, 'ticket_id' => $ticket->id];
    }
}
