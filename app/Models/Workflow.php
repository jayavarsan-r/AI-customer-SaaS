<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Workflow extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'description', 'is_active',
        'trigger', 'actions', 'run_count', 'success_count',
        'failure_count', 'last_triggered_at', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'is_active'          => 'boolean',
            'trigger'            => 'array',
            'actions'            => 'array',
            'last_triggered_at'  => 'datetime',
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

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderByDesc('priority');
    }

    public function scopeForEvent($query, string $event)
    {
        return $query->active()->whereJsonContains('trigger->event', $event);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function getTriggerEvent(): string
    {
        return $this->trigger['event'] ?? '';
    }

    public function getTriggerConditions(): array
    {
        return $this->trigger['conditions'] ?? [];
    }

    public function recordSuccess(): void
    {
        $this->increment('run_count');
        $this->increment('success_count');
        $this->update(['last_triggered_at' => now()]);
    }

    public function recordFailure(): void
    {
        $this->increment('run_count');
        $this->increment('failure_count');
        $this->update(['last_triggered_at' => now()]);
    }
}
