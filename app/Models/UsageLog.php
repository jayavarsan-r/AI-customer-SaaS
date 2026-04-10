<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null; // Only created_at needed

    protected $fillable = [
        'user_id', 'ticket_id', 'message_id', 'model_used',
        'operation_type', 'prompt_tokens', 'completion_tokens',
        'total_tokens', 'estimated_cost_usd', 'latency_ms',
        'was_cached', 'was_successful', 'error_type', 'usage_date',
    ];

    protected function casts(): array
    {
        return [
            'was_cached'      => 'boolean',
            'was_successful'  => 'boolean',
            'usage_date'      => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    // -------------------------------------------------------------------------
    // Cost Calculation (rough estimates — adjust per provider pricing)
    // -------------------------------------------------------------------------

    public static function estimateCost(string $model, int $promptTokens, int $completionTokens): float
    {
        // Prices per 1M tokens (USD) — update as providers change pricing
        $pricing = [
            'claude-opus-4-6'            => ['input' => 15.00, 'output' => 75.00],
            'claude-sonnet-4-6'          => ['input' => 3.00,  'output' => 15.00],
            'claude-haiku-4-5-20251001'  => ['input' => 0.25,  'output' => 1.25],
            'gpt-4o'                     => ['input' => 2.50,  'output' => 10.00],
            'gpt-4o-mini'                => ['input' => 0.15,  'output' => 0.60],
        ];

        $price = $pricing[$model] ?? ['input' => 3.00, 'output' => 15.00];

        return (($promptTokens / 1_000_000) * $price['input'])
             + (($completionTokens / 1_000_000) * $price['output']);
    }
}
