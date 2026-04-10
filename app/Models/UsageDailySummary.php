<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageDailySummary extends Model
{
    protected $fillable = [
        'user_id', 'summary_date', 'total_requests', 'successful_requests',
        'failed_requests', 'total_prompt_tokens', 'total_completion_tokens',
        'total_tokens', 'cached_responses', 'total_cost_usd', 'breakdown_by_operation',
    ];

    protected function casts(): array
    {
        return [
            'summary_date'           => 'date',
            'breakdown_by_operation' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
