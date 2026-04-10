<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'subject', 'description', 'status', 'priority',
        'channel', 'requester_email', 'requester_name',
        'ai_summary', 'summary_generated_at', 'first_response_at',
        'resolved_at', 'message_count', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'             => 'array',
            'summary_generated_at' => 'datetime',
            'first_response_at'    => 'datetime',
            'resolved_at'          => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'ticket_tag')
            ->withPivot(['is_ai_generated', 'confidence_score'])
            ->withTimestamps();
    }

    public function workflowRuns(): HasMany
    {
        return $this->morphMany(WorkflowRun::class, 'triggerable');
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeUrgent($query)
    {
        return $query->where('priority', 'urgent');
    }

    public function scopeNeedsSummary($query)
    {
        return $query->whereNull('ai_summary');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function markResolved(): void
    {
        $this->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
        ]);
    }

    public function getActiveConversation(): ?Conversation
    {
        return $this->conversations()->where('status', 'active')->latest()->first();
    }

    public function incrementMessageCount(): void
    {
        $this->increment('message_count');
    }
}
