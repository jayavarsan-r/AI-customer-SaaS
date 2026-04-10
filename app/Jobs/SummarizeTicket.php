<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\LLM\DTOs\LLMRequest;
use App\Services\LLM\LLMService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generates or refreshes an AI summary for a ticket.
 *
 * Queue: default (not time-critical)
 * Unique: per ticket to avoid duplicate summarizations running in parallel
 * Batchable: can be part of a workflow job batch
 */
class SummarizeTicket implements ShouldQueue, ShouldBeUnique
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $tries = 3;
    public int    $timeout = 60;
    public string $queue = 'default';

    public function __construct(
        public readonly Ticket $ticket,
    ) {}

    public function uniqueId(): string
    {
        return "summarize_ticket:{$this->ticket->id}";
    }

    public function handle(LLMService $llm): void
    {
        // Skip if batch was cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        $transcript = $this->buildTranscript();

        if (empty(trim($transcript))) {
            Log::info("SummarizeTicket skipped — no messages yet", ['ticket_id' => $this->ticket->id]);
            return;
        }

        $request = new LLMRequest(
            messages: [
                [
                    'role'    => 'user',
                    'content' => "Please summarize the following customer support conversation in 3-5 sentences. Focus on: (1) the customer's main issue, (2) what was tried, (3) current status/resolution.\n\n{$transcript}",
                ],
            ],
            systemPrompt:  "You are an expert at summarizing customer support tickets. Be concise, factual, and neutral.",
            maxTokens:     500,
            temperature:   0.1, // Low temperature for consistent summaries
            operationType: 'summarize',
            useCache:      true, // Same transcript = same summary
        );

        $response = $llm->complete(
            $request,
            userId:   $this->ticket->user_id,
            ticketId: $this->ticket->id,
        );

        $this->ticket->update([
            'ai_summary'           => $response->content,
            'summary_generated_at' => now(),
        ]);

        Log::info("SummarizeTicket completed", [
            'ticket_id'   => $this->ticket->id,
            'tokens_used' => $response->totalTokens,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SummarizeTicket permanently failed", [
            'ticket_id' => $this->ticket->id,
            'error'     => $exception->getMessage(),
        ]);
    }

    /**
     * Build a clean transcript from all ticket messages for summarization.
     */
    private function buildTranscript(): string
    {
        $messages = $this->ticket->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->where('status', 'completed')
            ->orderBy('created_at')
            ->get();

        if ($messages->isEmpty()) {
            return '';
        }

        $lines = [];
        $lines[] = "Subject: {$this->ticket->subject}";
        $lines[] = "Status: {$this->ticket->status}";
        $lines[] = "Priority: {$this->ticket->priority}";
        $lines[] = '';

        foreach ($messages as $msg) {
            $speaker = $msg->role === 'user' ? 'Customer' : 'Support Agent';
            $time    = $msg->created_at->format('Y-m-d H:i');
            $lines[] = "[{$time}] {$speaker}: {$msg->content}";
        }

        return implode("\n", $lines);
    }
}
