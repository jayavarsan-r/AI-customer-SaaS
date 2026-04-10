<?php

namespace App\Console\Commands;

use App\Models\UsageDailySummary;
use App\Models\UsageLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildDailyUsageSummaries extends Command
{
    protected $signature   = 'usage:rebuild-summaries {--date= : Specific date (Y-m-d), defaults to yesterday}';
    protected $description = 'Rebuild daily usage summary aggregations for reporting.';

    public function handle(): int
    {
        $date = $this->option('date') ? now()->parse($this->option('date')) : now()->subDay();

        $this->info("Rebuilding usage summaries for {$date->toDateString()}...");

        $summaries = UsageLog::query()
            ->select([
                'user_id',
                DB::raw('COUNT(*) as total_requests'),
                DB::raw('SUM(CASE WHEN was_successful = 1 THEN 1 ELSE 0 END) as successful_requests'),
                DB::raw('SUM(CASE WHEN was_successful = 0 THEN 1 ELSE 0 END) as failed_requests'),
                DB::raw('SUM(prompt_tokens) as total_prompt_tokens'),
                DB::raw('SUM(completion_tokens) as total_completion_tokens'),
                DB::raw('SUM(total_tokens) as total_tokens'),
                DB::raw('SUM(CASE WHEN was_cached = 1 THEN 1 ELSE 0 END) as cached_responses'),
                DB::raw('SUM(estimated_cost_usd) as total_cost_usd'),
            ])
            ->whereDate('usage_date', $date->toDateString())
            ->groupBy('user_id')
            ->get();

        $upserted = 0;
        foreach ($summaries as $summary) {
            UsageDailySummary::updateOrCreate(
                ['user_id' => $summary->user_id, 'summary_date' => $date->toDateString()],
                [
                    'total_requests'         => $summary->total_requests,
                    'successful_requests'    => $summary->successful_requests,
                    'failed_requests'        => $summary->failed_requests,
                    'total_prompt_tokens'    => $summary->total_prompt_tokens,
                    'total_completion_tokens' => $summary->total_completion_tokens,
                    'total_tokens'           => $summary->total_tokens,
                    'cached_responses'       => $summary->cached_responses,
                    'total_cost_usd'         => $summary->total_cost_usd ?? 0,
                ]
            );
            $upserted++;
        }

        $this->info("Rebuilt {$upserted} user summaries for {$date->toDateString()}.");

        return Command::SUCCESS;
    }
}
