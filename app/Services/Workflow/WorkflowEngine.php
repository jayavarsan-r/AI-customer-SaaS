<?php

namespace App\Services\Workflow;

use App\Models\Ticket;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\Workflow\Actions\ActionInterface;
use App\Services\Workflow\Actions\EmailAction;
use App\Services\Workflow\Actions\SummarizeAction;
use App\Services\Workflow\Actions\TagAction;
use App\Services\Workflow\Actions\WebhookAction;
use App\Services\Workflow\Actions\UpdateTicketAction;
use App\Services\Workflow\Conditions\ConditionEvaluator;
use Illuminate\Support\Facades\Log;

/**
 * Workflow Engine: evaluates conditions and executes action chains.
 *
 * Action types supported:
 *   - summarize   → triggers SummarizeTicket job
 *   - tag         → triggers AutoTagTicket job
 *   - email       → sends notification email
 *   - webhook     → POSTs to external URL
 *   - update_ticket → updates ticket fields (status, priority)
 */
class WorkflowEngine
{
    private array $actionRegistry;

    public function __construct(
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly SummarizeAction    $summarizeAction,
        private readonly TagAction          $tagAction,
        private readonly EmailAction        $emailAction,
        private readonly WebhookAction      $webhookAction,
        private readonly UpdateTicketAction $updateTicketAction,
    ) {
        $this->actionRegistry = [
            'summarize'     => $this->summarizeAction,
            'tag'           => $this->tagAction,
            'email'         => $this->emailAction,
            'webhook'       => $this->webhookAction,
            'update_ticket' => $this->updateTicketAction,
        ];
    }

    /**
     * Execute all actions in a workflow sequentially.
     * Failed individual actions are logged but don't halt subsequent actions.
     */
    public function execute(Workflow $workflow, Ticket $ticket, WorkflowRun $run): void
    {
        // Re-evaluate conditions at execution time (state may have changed)
        $conditions = $workflow->getTriggerConditions();

        if (!empty($conditions) && !$this->conditionEvaluator->evaluate($ticket, $conditions)) {
            Log::info("WorkflowEngine: conditions no longer met at execution time, skipping", [
                'workflow_id' => $workflow->id,
                'ticket_id'   => $ticket->id,
            ]);
            $run->update(['status' => 'completed']);
            return;
        }

        $actions = $workflow->actions ?? [];

        foreach ($actions as $actionConfig) {
            $type   = $actionConfig['type'] ?? '';
            $params = $actionConfig['params'] ?? [];

            if (!isset($this->actionRegistry[$type])) {
                Log::warning("WorkflowEngine: unknown action type '{$type}'", ['workflow_id' => $workflow->id]);
                $run->appendFailedAction($type, "Unknown action type: {$type}");
                continue;
            }

            try {
                /** @var ActionInterface $action */
                $action = $this->actionRegistry[$type];
                $result = $action->execute($ticket, $params);
                $run->appendCompletedAction($type, $result);

                Log::debug("WorkflowEngine: action '{$type}' completed", [
                    'workflow_id' => $workflow->id,
                    'ticket_id'   => $ticket->id,
                ]);
            } catch (\Throwable $e) {
                Log::error("WorkflowEngine: action '{$type}' failed", [
                    'workflow_id' => $workflow->id,
                    'ticket_id'   => $ticket->id,
                    'error'       => $e->getMessage(),
                ]);
                $run->appendFailedAction($type, $e->getMessage());
                // Continue to next action (partial success is valid)
            }
        }
    }

    /**
     * Find all active workflows matching an event and dispatch them.
     * Called by event listeners.
     */
    public function dispatchForEvent(string $event, Ticket $ticket): void
    {
        $workflows = Workflow::forEvent($event)
            ->where('user_id', $ticket->user_id)
            ->get();

        if ($workflows->isEmpty()) {
            return;
        }

        foreach ($workflows as $workflow) {
            // Check conditions before even dispatching the job
            $conditions = $workflow->getTriggerConditions();
            if (!empty($conditions) && !$this->conditionEvaluator->evaluate($ticket, $conditions)) {
                continue;
            }

            $run = WorkflowRun::create([
                'workflow_id'      => $workflow->id,
                'user_id'          => $ticket->user_id,
                'triggerable_type' => Ticket::class,
                'triggerable_id'   => $ticket->id,
                'status'           => 'pending',
            ]);

            \App\Jobs\ExecuteWorkflow::dispatch($workflow, $ticket, $run)
                ->onQueue('default');
        }

        Log::info("WorkflowEngine: dispatched {$workflows->count()} workflow(s) for event '{$event}'", [
            'ticket_id' => $ticket->id,
        ]);
    }
}
