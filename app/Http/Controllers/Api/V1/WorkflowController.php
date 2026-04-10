<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    /**
     * GET /api/v1/workflows
     */
    public function index(Request $request): JsonResponse
    {
        $workflows = $request->user()->workflows()
            ->withCount('runs')
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $workflows->map(fn ($w) => $this->workflowResource($w)),
            'meta' => [
                'total'        => $workflows->total(),
                'current_page' => $workflows->currentPage(),
                'last_page'    => $workflows->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/workflows
     *
     * Create a workflow.
     *
     * Example body:
     * {
     *   "name": "Auto-process urgent tickets",
     *   "trigger": {
     *     "event": "ticket.created",
     *     "conditions": [
     *       { "field": "priority", "operator": "equals", "value": "urgent" }
     *     ]
     *   },
     *   "actions": [
     *     { "type": "summarize" },
     *     { "type": "tag" },
     *     { "type": "email", "params": { "to": "support-lead@company.com" } }
     *   ]
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                          => ['required', 'string', 'max:255'],
            'description'                   => ['nullable', 'string'],
            'trigger'                       => ['required', 'array'],
            'trigger.event'                 => ['required', 'string', 'in:ticket.created,ticket.updated,ticket.resolved,message.created'],
            'trigger.conditions'            => ['nullable', 'array'],
            'trigger.conditions.*.field'    => ['required', 'string'],
            'trigger.conditions.*.operator' => ['required', 'string'],
            'trigger.conditions.*.value'    => ['required'],
            'actions'                       => ['required', 'array', 'min:1', 'max:10'],
            'actions.*.type'                => ['required', 'string', 'in:summarize,tag,email,webhook,update_ticket'],
            'actions.*.params'              => ['nullable', 'array'],
            'is_active'                     => ['boolean'],
            'priority'                      => ['integer', 'min:0', 'max:100'],
        ]);

        $workflow = $request->user()->workflows()->create([
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? null,
            'trigger'     => $validated['trigger'],
            'actions'     => $validated['actions'],
            'is_active'   => $validated['is_active'] ?? true,
            'priority'    => $validated['priority'] ?? 0,
        ]);

        return response()->json($this->workflowResource($workflow), 201);
    }

    /**
     * GET /api/v1/workflows/{uuid}
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $workflow = $request->user()->workflows()
            ->where('uuid', $uuid)
            ->with(['runs' => fn ($q) => $q->latest()->take(10)])
            ->firstOrFail();

        return response()->json($this->workflowResource($workflow, includeRuns: true));
    }

    /**
     * PATCH /api/v1/workflows/{uuid}
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $workflow = $request->user()->workflows()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name'        => ['string', 'max:255'],
            'description' => ['nullable', 'string'],
            'trigger'     => ['array'],
            'actions'     => ['array', 'min:1', 'max:10'],
            'is_active'   => ['boolean'],
            'priority'    => ['integer', 'min:0', 'max:100'],
        ]);

        $workflow->update($validated);

        return response()->json($this->workflowResource($workflow));
    }

    /**
     * DELETE /api/v1/workflows/{uuid}
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $request->user()->workflows()->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Workflow deleted.']);
    }

    /**
     * POST /api/v1/workflows/{uuid}/test
     * Manually trigger a workflow against a specific ticket for testing.
     */
    public function test(Request $request, string $uuid): JsonResponse
    {
        $workflow = $request->user()->workflows()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'ticket_uuid' => ['required', 'string'],
        ]);

        $ticket = $request->user()->tickets()->where('uuid', $validated['ticket_uuid'])->firstOrFail();

        $run = \App\Models\WorkflowRun::create([
            'workflow_id'      => $workflow->id,
            'user_id'          => $request->user()->id,
            'triggerable_type' => Ticket::class,
            'triggerable_id'   => $ticket->id,
            'status'           => 'pending',
        ]);

        \App\Jobs\ExecuteWorkflow::dispatch($workflow, $ticket, $run)->onQueue('default');

        return response()->json([
            'message' => 'Workflow test dispatched.',
            'run_id'  => $run->uuid,
        ]);
    }

    /**
     * GET /api/v1/workflows/{uuid}/runs
     */
    public function runs(Request $request, string $uuid): JsonResponse
    {
        $workflow = $request->user()->workflows()->where('uuid', $uuid)->firstOrFail();

        $runs = $workflow->runs()->latest()->paginate(20);

        return response()->json([
            'data' => $runs->map(fn ($run) => [
                'id'                 => $run->uuid,
                'status'             => $run->status,
                'actions_completed'  => $run->actions_completed,
                'actions_failed'     => $run->actions_failed,
                'error_message'      => $run->error_message,
                'execution_time_ms'  => $run->execution_time_ms,
                'created_at'         => $run->created_at->toIso8601String(),
            ]),
            'meta' => ['total' => $runs->total()],
        ]);
    }

    private function workflowResource(Workflow $workflow, bool $includeRuns = false): array
    {
        $data = [
            'id'                => $workflow->uuid,
            'name'              => $workflow->name,
            'description'       => $workflow->description,
            'is_active'         => $workflow->is_active,
            'trigger'           => $workflow->trigger,
            'actions'           => $workflow->actions,
            'priority'          => $workflow->priority,
            'run_count'         => $workflow->run_count,
            'success_count'     => $workflow->success_count,
            'failure_count'     => $workflow->failure_count,
            'last_triggered_at' => $workflow->last_triggered_at?->toIso8601String(),
            'created_at'        => $workflow->created_at->toIso8601String(),
        ];

        if ($includeRuns && $workflow->relationLoaded('runs')) {
            $data['recent_runs'] = $workflow->runs->map(fn ($run) => [
                'id'                => $run->uuid,
                'status'            => $run->status,
                'execution_time_ms' => $run->execution_time_ms,
                'created_at'        => $run->created_at->toIso8601String(),
            ]);
        }

        return $data;
    }
}
