<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id', 'user_id', 'title', 'model_used',
        'total_prompt_tokens', 'total_completion_tokens',
        'message_count', 'status', 'system_prompt',
    ];

    protected function casts(): array
    {
        return [
            'system_prompt' => 'array',
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

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the message history formatted for LLM context window.
     * Limits to last N messages to prevent context overflow.
     */
    public function getContextWindow(int $maxMessages = 20): array
    {
        return $this->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->where('status', 'completed')
            ->latest()
            ->take($maxMessages)
            ->get()
            ->sortBy('created_at')
            ->map(fn (Message $m) => [
                'role'    => $m->role,
                'content' => $m->content,
            ])
            ->values()
            ->toArray();
    }

    public function getTotalTokens(): int
    {
        return $this->total_prompt_tokens + $this->total_completion_tokens;
    }

    public function addTokenUsage(int $promptTokens, int $completionTokens): void
    {
        $this->increment('total_prompt_tokens', $promptTokens);
        $this->increment('total_completion_tokens', $completionTokens);
        $this->increment('message_count');
    }
}
