<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id', 'ticket_id', 'user_id', 'role',
        'content', 'prompt_tokens', 'completion_tokens', 'total_tokens',
        'model_used', 'processing_time_ms', 'status', 'failure_reason',
        'retry_count', 'is_cached', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_cached' => 'boolean',
            'metadata'  => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn (self $model) => $model->uuid = (string) Str::uuid());
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    // -------------------------------------------------------------------------
    // State Machine
    // -------------------------------------------------------------------------

    public function markProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markCompleted(int $promptTokens, int $completionTokens, string $model, float $ms): void
    {
        $this->update([
            'status'             => 'completed',
            'prompt_tokens'      => $promptTokens,
            'completion_tokens'  => $completionTokens,
            'total_tokens'       => $promptTokens + $completionTokens,
            'model_used'         => $model,
            'processing_time_ms' => $ms,
        ]);
    }

    public function markFailed(string $reason, bool $incrementRetry = true): void
    {
        $updates = ['status' => 'failed', 'failure_reason' => $reason];
        if ($incrementRetry) {
            $updates['retry_count'] = $this->retry_count + 1;
        }
        $this->update($updates);
    }
}
