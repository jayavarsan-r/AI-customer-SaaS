<?php

use App\Console\Commands\PruneUsageLogs;
use App\Console\Commands\RebuildDailyUsageSummaries;
use Illuminate\Support\Facades\Schedule;

// Daily: rebuild usage summaries for reporting
Schedule::command('usage:rebuild-summaries')->dailyAt('01:00');

// Weekly: prune old usage logs (keep 90 days)
Schedule::command('usage:prune --days=90')->weekly()->sundays()->at('02:00');

// Every 5 minutes: retry stalled jobs that have been stuck in 'processing' > 10 min
Schedule::command('queue:retry all')->everyFiveMinutes();
