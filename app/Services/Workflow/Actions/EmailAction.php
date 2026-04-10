<?php

namespace App\Services\Workflow\Actions;

use App\Models\Ticket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailAction implements ActionInterface
{
    public function execute(Ticket $ticket, array $params = []): mixed
    {
        $to      = $params['to'] ?? $ticket->requester_email ?? $ticket->user->email;
        $subject = $params['subject'] ?? "Update on your ticket: {$ticket->subject}";
        $body    = $params['body'] ?? $this->defaultBody($ticket);

        if (empty($to)) {
            Log::warning("EmailAction: no recipient found", ['ticket_id' => $ticket->id]);
            return ['sent' => false, 'reason' => 'no_recipient'];
        }

        Mail::raw($body, function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject);
        });

        return ['sent' => true, 'to' => $to, 'subject' => $subject];
    }

    private function defaultBody(Ticket $ticket): string
    {
        return "Hello,\n\nYour support ticket #{$ticket->uuid} ('{$ticket->subject}') has been updated.\n\nStatus: {$ticket->status}\n\nThank you for contacting us.";
    }
}
