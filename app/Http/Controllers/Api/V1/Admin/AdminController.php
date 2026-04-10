<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsageDailySummary;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class AdminController extends Controller
{
    /**
     * GET /api/v1/admin/health
     * System health check (database, Redis, queue workers).
     */
    public function health(): JsonResponse
    {
        $checks = [];

        // Database
        try {
            DB::select('SELECT 1');
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'error', 'error' => $e->getMessage()];
        }

        // Redis
        try {
            Redis::ping();
            $info             = Redis::info('server');
            $checks['redis']  = [
                'status'       => 'ok',
                'version'      => $info['redis_version'] ?? 'unknown',
                'used_memory'  => $info['used_memory_human'] ?? 'unknown',
            ];
        } catch (\Throwable $e) {
            $checks['redis'] = ['status' => 'error', 'error' => $e->getMessage()];
        }

        // Queue depths
        try {
            $checks['queues'] = [
                'high'    => $this->getQueueSize('high'),
                'default' => $this->getQueueSize('default'),
                'low'     => $this->getQueueSize('low'),
            ];
        } catch (\Throwable $e) {
            $checks['queues'] = ['status' => 'error', 'error' => $e->getMessage()];
        }

        $allOk      = collect($checks)->every(fn ($c) => ($c['status'] ?? 'ok') === 'ok');
        $statusCode = $allOk ? 200 : 503;

        return response()->json([
            'status'    => $allOk ? 'healthy' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'checks'    => $checks,
        ], $statusCode);
    }

    /**
     * GET /api/v1/admin/failed-jobs
     * List failed jobs with pagination.
     */
    public function failedJobs(Request $request): JsonResponse
    {
        $jobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->paginate(20);

        $formatted = collect($jobs->items())->map(function ($job) {
            $payload = json_decode($job->payload, true);
            return [
                'id'          => $job->id,
                'uuid'        => $job->uuid,
                'queue'       => $job->queue,
                'job_class'   => $payload['displayName'] ?? 'Unknown',
                'exception'   => substr($job->exception, 0, 500),
                'failed_at'   => $job->failed_at,
            ];
        });

        return response()->json([
            'data' => $formatted,
            'meta' => ['total' => $jobs->total()],
        ]);
    }

    /**
     * POST /api/v1/admin/failed-jobs/{uuid}/retry
     * Retry a specific failed job.
     */
    public function retryFailedJob(string $uuid): JsonResponse
    {
        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        if (!$job) {
            return response()->json(['error' => 'Failed job not found.'], 404);
        }

        // Laravel's artisan queue:retry equivalent
        $payload = json_decode($job->payload, true);
        Queue::connection($job->connection)->pushRaw($job->payload, $job->queue);
        DB::table('failed_jobs')->where('uuid', $uuid)->delete();

        return response()->json(['message' => "Job {$uuid} queued for retry."]);
    }

    /**
     * GET /api/v1/admin/usage-stats
     * Aggregate token usage across all users.
     */
    public function usageStats(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);

        $stats = UsageLog::query()
            ->select([
                DB::raw('DATE(usage_date) as date'),
                DB::raw('COUNT(*) as total_requests'),
                DB::raw('SUM(total_tokens) as total_tokens'),
                DB::raw('SUM(estimated_cost_usd) as total_cost_usd'),
                DB::raw('SUM(CASE WHEN was_cached = 1 THEN 1 ELSE 0 END) as cached_responses'),
                DB::raw('AVG(latency_ms) as avg_latency_ms'),
            ])
            ->where('usage_date', '>=', now()->subDays($days)->toDateString())
            ->groupBy('date')
            ->orderByDesc('date')
            ->get();

        $topUsers = UsageLog::query()
            ->select([
                'user_id',
                DB::raw('SUM(total_tokens) as total_tokens'),
                DB::raw('COUNT(*) as request_count'),
            ])
            ->where('usage_date', '>=', now()->subDays($days)->toDateString())
            ->groupBy('user_id')
            ->orderByDesc('total_tokens')
            ->take(10)
            ->with('user:id,name,email,plan')
            ->get();

        $breakdown = UsageLog::query()
            ->select([
                'operation_type',
                DB::raw('SUM(total_tokens) as total_tokens'),
                DB::raw('COUNT(*) as count'),
            ])
            ->where('usage_date', '>=', now()->subDays($days)->toDateString())
            ->groupBy('operation_type')
            ->get();

        return response()->json([
            'period_days' => $days,
            'daily_stats' => $stats,
            'top_users'   => $topUsers,
            'by_operation' => $breakdown,
            'totals' => [
                'tokens'   => $stats->sum('total_tokens'),
                'requests' => $stats->sum('total_requests'),
                'cost_usd' => round($stats->sum('total_cost_usd'), 4),
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/users
     * List users with usage summaries.
     */
    public function users(Request $request): JsonResponse
    {
        $users = User::withCount(['tickets', 'conversations'])
            ->with(['usageDailySummaries' => fn ($q) => $q->where('summary_date', '>=', now()->subDays(7)->toDateString())])
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $users->map(fn (User $user) => [
                'id'               => $user->uuid,
                'name'             => $user->name,
                'email'            => $user->email,
                'plan'             => $user->plan,
                'is_active'        => $user->is_active,
                'tickets_count'    => $user->tickets_count,
                'conversations_count' => $user->conversations_count,
                'tokens_this_week' => $user->usageDailySummaries->sum('total_tokens'),
                'created_at'       => $user->created_at->toIso8601String(),
            ]),
            'meta' => ['total' => $users->total()],
        ]);
    }

    /**
     * GET /api/v1/admin/queue-stats
     * Real-time queue depth metrics.
     */
    public function queueStats(): JsonResponse
    {
        return response()->json([
            'queues' => [
                'high'    => ['size' => $this->getQueueSize('high'),    'label' => 'High Priority (Chat)'],
                'default' => ['size' => $this->getQueueSize('default'), 'label' => 'Default (Workflows, Tags)'],
                'low'     => ['size' => $this->getQueueSize('low'),     'label' => 'Low Priority (Cleanup)'],
            ],
            'failed_count' => DB::table('failed_jobs')->count(),
            'timestamp'    => now()->toIso8601String(),
        ]);
    }

    private function getQueueSize(string $queue): int
    {
        try {
            return (int) Redis::llen("queues:{$queue}");
        } catch (\Throwable) {
            return -1;
        }
    }
}
