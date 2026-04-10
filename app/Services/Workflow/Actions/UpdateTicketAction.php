<?php

namespace App\Services\Workflow\Actions;

use App\Models\Ticket;

class UpdateTicketAction implements ActionInterface
{
    private const ALLOWED_FIELDS = ['status', 'priority'];

    public function execute(Ticket $ticket, array $params = []): mixed
    {
        $updates = array_intersect_key($params, array_flip(self::ALLOWED_FIELDS));

        if (empty($updates)) {
            return ['updated' => false, 'reason' => 'no_valid_fields'];
        }

        $ticket->update($updates);

        return ['updated' => true, 'fields' => $updates];
    }
}
