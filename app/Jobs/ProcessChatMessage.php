<?php

namespace App\Jobs;

use App\Events\MessageCompleted;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\LLM\DTOs\LLMRequest;
use App\Services\LLM\Exceptions\LLMRateLimitException;
use App\Services\LLM\LLMService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async job: sends a user message to the LLM and stores the AI response.
 *
 * Queue priority: high (real-time chat must be fast)
 * Retry strategy: 3 attempts with exponential backoff
 * Unique: per message (prevents duplicate processing if job is queued twice)
 */
class ProcessChatMessage implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $tries = 3;
    public int    $timeout = 90;
    public int    $maxExceptions = 2;
    public string $queue = 'high';

    public int $backoff = 5; // seconds before retry

    public function __construct(
        public readonly Message      $message,
        public readonly Conversation $conversation,
    ) {}

    /**
     * Unique per message — prevents duplicate AI responses.
     */
    public function uniqueId(): string
    {
        return "chat_message:{$this->message->id}";
    }

    public function handle(LLMService $llm): void
    {
        $this->message->markProcessing();

        try {
            $contextMessages = $this->conversation->getContextWindow(20);
            $systemPrompt    = $this->buildSystemPrompt();

            $llmRequest = new LLMRequest(
                messages:      $contextMessages,
                systemPrompt:  $systemPrompt,
                model:         config('llm.default_model'),
                maxTokens:     config('llm.max_tokens', 2048),
                temperature:   0.5,
                operationType: 'chat',
                useCache:      false, // Chat should always be fresh
                userId:        (string) $this->message->user_id,
            );

            $response = $llm->complete(
                $llmRequest,
                userId:    $this->message->user_id,
                ticketId:  $this->message->ticket_id,
                messageId: $this->message->id,
            );

            // Persist AI response as a new message
            $aiMessage = Message::create([
                'conversation_id'    => $this->conversation->id,
                'ticket_id'          => $this->message->ticket_id,
                'user_id'            => $this->message->user_id,
                'role'               => 'assistant',
                'content'            => $response->content,
                'prompt_tokens'      => $response->promptTokens,
                'completion_tokens'  => $response->completionTokens,
                'total_tokens'       => $response->totalTokens,
                'model_used'         => $response->model,
                'processing_time_ms' => $response->latencyMs,
                'status'             => 'completed',
                'is_cached'          => $response->fromCache,
            ]);

            // Update conversation token totals
            $this->conversation->addTokenUsage($response->promptTokens, $response->completionTokens);

            // Mark original user message as completed
            $this->message->markCompleted(
                $response->promptTokens,
                $response->completionTokens,
                $response->model,
                $response->latencyMs,
            );

            // Increment ticket message count
            $this->message->ticket->incrementMessageCount();

            // Fire event for websocket broadcast / other listeners
            event(new MessageCompleted($aiMessage, $this->conversation));

            Log::info("ProcessChatMessage completed", [
                'message_id'   => $this->message->id,
                'tokens_used'  => $response->totalTokens,
                'latency_ms'   => round($response->latencyMs),
            ]);

        } catch (LLMRateLimitException $e) {
            Log::warning("LLM rate limit hit in ProcessChatMessage, will retry", [
                'message_id'  => $this->message->id,
                'retry_after' => $e->retryAfterSeconds,
            ]);
            // Release back to queue with a delay matching the provider's retry-after
            $this->release($e->retryAfterSeconds);
        } catch (\Throwable $e) {
            $this->message->markFailed($e->getMessage());
            Log::error("ProcessChatMessage failed", [
                'message_id' => $this->message->id,
                'error'      => $e->getMessage(),
                'class'      => $e::class,
            ]);
            throw $e; // Let Laravel's retry mechanism handle it
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->message->markFailed("Job permanently failed: {$exception->getMessage()}", incrementRetry: false);

        Log::error("ProcessChatMessage permanently failed", [
            'message_id' => $this->message->id,
            'error'      => $exception->getMessage(),
        ]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(15);
    }

    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
You are a helpful AI customer support assistant. Be concise, accurate, and professional.

Guidelines:
- Answer questions directly and clearly
- If you don't know something, say so honestly
- Escalate to human agents when the issue is complex or sensitive
- Keep responses under 500 words unless the question explicitly requires detail
- Always maintain a helpful, empathetic tone

Current date: {$this->currentDate()}
PROMPT;
    }

    private function currentDate(): string
    {
        return now()->format('Y-m-d');
    }
}
