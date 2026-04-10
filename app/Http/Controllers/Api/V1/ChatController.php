<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessChatMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\LLM\DTOs\LLMRequest;
use App\Services\LLM\LLMService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private readonly LLMService $llm,
    ) {}

    /**
     * POST /api/v1/tickets/{uuid}/messages
     *
     * Send a message. Queues async LLM processing by default.
     * Pass ?stream=true for a streaming response.
     */
    public function sendMessage(Request $request, string $uuid): JsonResponse|StreamedResponse
    {
        $ticket = $request->user()->tickets()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:10000'],
            'stream'  => ['boolean'],
        ]);

        // Ensure active conversation exists
        $conversation = $ticket->getActiveConversation()
            ?? $this->createConversation($ticket);

        // Persist user message immediately
        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'ticket_id'       => $ticket->id,
            'user_id'         => $request->user()->id,
            'role'            => 'user',
            'content'         => $validated['content'],
            'status'          => 'pending',
        ]);

        // Stream mode: respond in real-time (no queue)
        if ($request->boolean('stream')) {
            return $this->streamResponse($request, $userMessage, $conversation);
        }

        // Default async mode: queue the LLM job
        ProcessChatMessage::dispatch($userMessage, $conversation)
            ->onQueue('high');

        return response()->json([
            'message' => [
                'id'         => $userMessage->uuid,
                'role'       => 'user',
                'content'    => $userMessage->content,
                'status'     => 'pending',
                'created_at' => $userMessage->created_at->toIso8601String(),
            ],
            'meta' => [
                'async'           => true,
                'conversation_id' => $conversation->uuid,
                'note'            => 'AI response will be delivered asynchronously. Subscribe to ticket.{id} websocket channel.',
            ],
        ], 202);
    }

    /**
     * GET /api/v1/tickets/{uuid}/messages
     * List all messages in the ticket's active conversation.
     */
    public function listMessages(Request $request, string $uuid): JsonResponse
    {
        $ticket = $request->user()->tickets()->where('uuid', $uuid)->firstOrFail();

        $conversation = $ticket->getActiveConversation();

        if (!$conversation) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $messages = $conversation->messages()
            ->whereIn('status', ['completed', 'pending', 'processing'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Message $m) => [
                'id'                 => $m->uuid,
                'role'               => $m->role,
                'content'            => $m->content,
                'status'             => $m->status,
                'model_used'         => $m->model_used,
                'total_tokens'       => $m->total_tokens,
                'processing_time_ms' => $m->processing_time_ms,
                'is_cached'          => $m->is_cached,
                'created_at'         => $m->created_at->toIso8601String(),
            ]);

        return response()->json([
            'data' => $messages,
            'meta' => [
                'total'              => $messages->count(),
                'conversation_id'    => $conversation->uuid,
                'total_tokens_used'  => $conversation->getTotalTokens(),
            ],
        ]);
    }

    /**
     * POST /api/v1/tickets/{uuid}/conversations
     * Create a fresh conversation (resets context window).
     */
    public function newConversation(Request $request, string $uuid): JsonResponse
    {
        $ticket = $request->user()->tickets()->where('uuid', $uuid)->firstOrFail();

        // Archive the current active conversation
        $ticket->conversations()->where('status', 'active')->update(['status' => 'archived']);

        $conversation = $this->createConversation($ticket);

        return response()->json([
            'conversation_id' => $conversation->uuid,
            'message'         => 'New conversation started.',
        ], 201);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function createConversation(Ticket $ticket): Conversation
    {
        return Conversation::create([
            'ticket_id'   => $ticket->id,
            'user_id'     => $ticket->user_id,
            'title'       => "Conversation for: {$ticket->subject}",
            'status'      => 'active',
            'model_used'  => config('llm.default_model'),
        ]);
    }

    private function streamResponse(Request $request, Message $userMessage, Conversation $conversation): StreamedResponse
    {
        $userMessage->markProcessing();
        $contextMessages = $conversation->getContextWindow(20);
        $startTime       = microtime(true);

        return response()->stream(function () use ($request, $userMessage, $conversation, $contextMessages, $startTime) {

            $llmRequest = new LLMRequest(
                messages:      $contextMessages,
                systemPrompt:  $this->buildSystemPrompt(),
                model:         config('llm.default_model'),
                maxTokens:     config('llm.max_tokens', 2048),
                temperature:   0.5,
                operationType: 'chat',
                useCache:      false,
            );

            $fullContent = '';

            try {
                $response = $this->llm->stream(
                    $llmRequest,
                    onChunk: function (string $chunk) use (&$fullContent) {
                        $fullContent .= $chunk;
                        echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                        ob_flush();
                        flush();
                    },
                    userId:   $request->user()->id,
                    ticketId: $userMessage->ticket_id,
                    messageId: $userMessage->id,
                );

                // Persist AI response
                $aiMessage = Message::create([
                    'conversation_id'    => $conversation->id,
                    'ticket_id'          => $userMessage->ticket_id,
                    'user_id'            => $userMessage->user_id,
                    'role'               => 'assistant',
                    'content'            => $fullContent,
                    'prompt_tokens'      => $response->promptTokens,
                    'completion_tokens'  => $response->completionTokens,
                    'total_tokens'       => $response->totalTokens,
                    'model_used'         => $response->model,
                    'processing_time_ms' => $response->latencyMs,
                    'status'             => 'completed',
                ]);

                $userMessage->markCompleted(
                    $response->promptTokens, $response->completionTokens,
                    $response->model, $response->latencyMs,
                );
                $conversation->addTokenUsage($response->promptTokens, $response->completionTokens);

                echo "data: " . json_encode([
                    'done'         => true,
                    'message_id'   => $aiMessage->uuid,
                    'total_tokens' => $response->totalTokens,
                ]) . "\n\n";

            } catch (\Throwable $e) {
                $userMessage->markFailed($e->getMessage());
                echo "data: " . json_encode(['error' => 'LLM processing failed.']) . "\n\n";
            }

            ob_flush();
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function buildSystemPrompt(): string
    {
        return 'You are a helpful AI customer support assistant. Be concise, accurate, and professional. Keep responses under 500 words unless explicitly required.';
    }
}
