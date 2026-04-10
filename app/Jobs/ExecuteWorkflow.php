<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Executes a single workflow against a triggering model.
 *
 * This job is dispatched by WorkflowDispatcher after event matching.
 * Each action in the workflow is chained as a separate job for isolation.
 */
class ExecuteWorkflow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $tries = 2;
    public int    $timeout = 120;
    public string $queue = 'default';

    public function __construct(
        public readonly Workflow    $workflow,
        public readonly Ticket      $ticket,
        public readonly WorkflowRun $run,
    ) {}

    public function handle(WorkflowEngine $engine): void
    {
        $startTime = microtime(true);

        $this->run->update(['status' => 'running']);

        try {
            $engine->execute($this->workflow, $this->ticket, $this->run);

            $executionMs = (microtime(true) - $startTime) * 1000;
            $this->run->update([
                'status'            => 'completed',
                'execution_time_ms' => $executionMs,
            ]);

            $this->workflow->recordSuccess();

            Log::info("ExecuteWorkflow completed", [
                'workflow_id'  => $this->workflow->id,
                'ticket_id'    => $this->ticket->id,
                'run_id'       => $this->run->id,
                'execution_ms' => round($executionMs),
            ]);

        } catch (\Throwable $e) {
            $executionMs = (microtime(true) - $startTime) * 1000;
            $this->run->update([
                'status'            => 'failed',
                'error_message'     => $e->getMessage(),
                'execution_time_ms' => $executionMs,
            ]);

            $this->workflow->recordFailure();

            Log::error("ExecuteWorkflow failed", [
                'workflow_id' => $this->workflow->id,
                'ticket_id'   => $this->ticket->id,
                'run_id'      => $this->run->id,
                'error'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->run->update([
            'status'        => 'failed',
            'error_message' => "Permanently failed: {$exception->getMessage()}",
        ]);
        $this->workflow->recordFailure();
    }
}
