<?php

namespace App\Services\Workflow\Actions;

use App\Models\Ticket;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class WebhookAction implements ActionInterface
{
    public function execute(Ticket $ticket, array $params = []): mixed
    {
        $url    = $params['url'] ?? null;
        $method = strtoupper($params['method'] ?? 'POST');
        $headers = $params['headers'] ?? [];

        if (!$url) {
            return ['sent' => false, 'reason' => 'no_url_configured'];
        }

        $payload = array_merge([
            'event'     => 'ticket.workflow_triggered',
            'ticket_id' => $ticket->uuid,
            'subject'   => $ticket->subject,
            'status'    => $ticket->status,
            'priority'  => $ticket->priority,
            'timestamp' => now()->toIso8601String(),
        ], $params['payload'] ?? []);

        try {
            $client   = new Client(['timeout' => 10]);
            $response = $client->request($method, $url, [
                'json'    => $payload,
                'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
            ]);

            return [
                'sent'        => true,
                'url'         => $url,
                'status_code' => $response->getStatusCode(),
            ];
        } catch (RequestException $e) {
            Log::warning("WebhookAction failed", [
                'ticket_id' => $ticket->id,
                'url'       => $url,
                'error'     => $e->getMessage(),
            ]);
            return ['sent' => false, 'url' => $url, 'error' => $e->getMessage()];
        }
    }
}
