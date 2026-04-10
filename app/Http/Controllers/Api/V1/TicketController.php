<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\TicketCreated;
use App\Http\Controllers\Controller;
use App\Jobs\AutoTagTicket;
use App\Jobs\SummarizeTicket;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketController extends Controller
{
    /**
     * GET /api/v1/tickets
     * List paginated tickets for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->tickets()
            ->with(['tags', 'conversations'])
            ->latest();

        // Filters
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $tickets = $query->paginate(20);

        return response()->json([
            'data' => $tickets->items(),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page'    => $tickets->lastPage(),
                'per_page'     => $tickets->perPage(),
                'total'        => $tickets->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/tickets
     * Create a new ticket and fire the TicketCreated event (triggers workflows).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject'         => ['required', 'string', 'max:500'],
            'description'     => ['nullable', 'string', 'max:10000'],
            'priority'        => ['in:low,normal,high,urgent'],
            'channel'         => ['in:api,email,web,slack'],
            'requester_email' => ['nullable', 'email'],
            'requester_name'  => ['nullable', 'string', 'max:255'],
            'metadata'        => ['nullable', 'array'],
        ]);

        $ticket = $request->user()->tickets()->create(array_merge($validated, [
            'status'   => 'open',
            'priority' => $validated['priority'] ?? 'normal',
            'channel'  => $validated['channel']  ?? 'api',
        ]));

        // Fire async event — triggers all matching workflows
        event(new TicketCreated($ticket));

        return response()->json($this->ticketResource($ticket), 201);
    }

    /**
     * GET /api/v1/tickets/{uuid}
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $ticket = $request->user()->tickets()
            ->where('uuid', $uuid)
            ->with(['tags', 'conversations.messages'])
            ->firstOrFail();

        return response()->json($this->ticketResource($ticket));
    }

    /**
     * PATCH /api/v1/tickets/{uuid}
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $ticket = $request->user()->tickets()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'status'   => ['in:open,in_progress,waiting,resolved,closed'],
            'priority' => ['in:low,normal,high,urgent'],
            'subject'  => ['string', 'max:500'],
        ]);

        $ticket->update($validated);

        return response()->json($this->ticketResource($ticket));
    }

    /**
     * DELETE /api/v1/tickets/{uuid}
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $ticket = $request->user()->tickets()->where('uuid', $uuid)->firstOrFail();
        $ticket->delete();

        return response()->json(['message' => 'Ticket deleted.']);
    }

    /**
     * POST /api/v1/tickets/{uuid}/summarize
     * Manually trigger AI summarization.
     */
    public function summarize(Request $request, string $uuid): JsonResponse
    {
        $ticket = $request->user()->tickets()->where('uuid', $uuid)->firstOrFail();

        SummarizeTicket::dispatch($ticket)->onQueue('default');

        return response()->json(['message' => 'Summarization queued.', 'ticket_id' => $ticket->uuid]);
    }

    /**
     * POST /api/v1/tickets/{uuid}/tag
     * Manually trigger auto-tagging.
     */
    public function tag(Request $request, string $uuid): JsonResponse
    {
        $ticket = $request->user()->tickets()->where('uuid', $uuid)->firstOrFail();

        AutoTagTicket::dispatch($ticket)->onQueue('default');

        return response()->json(['message' => 'Auto-tagging queued.', 'ticket_id' => $ticket->uuid]);
    }

    private function ticketResource(Ticket $ticket): array
    {
        return [
            'id'                   => $ticket->uuid,
            'subject'              => $ticket->subject,
            'description'          => $ticket->description,
            'status'               => $ticket->status,
            'priority'             => $ticket->priority,
            'channel'              => $ticket->channel,
            'requester_email'      => $ticket->requester_email,
            'requester_name'       => $ticket->requester_name,
            'ai_summary'           => $ticket->ai_summary,
            'summary_generated_at' => $ticket->summary_generated_at?->toIso8601String(),
            'message_count'        => $ticket->message_count,
            'tags'                 => $ticket->tags?->map(fn ($t) => [
                'name'       => $t->name,
                'category'   => $t->category,
                'color'      => $t->color,
                'confidence' => $t->pivot?->confidence_score,
            ]),
            'created_at'           => $ticket->created_at->toIso8601String(),
            'updated_at'           => $ticket->updated_at->toIso8601String(),
        ];
    }
}
