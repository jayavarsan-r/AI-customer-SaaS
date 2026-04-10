<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class WorkflowRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'workflow_id', 'user_id', 'triggerable_type', 'triggerable_id',
        'status', 'actions_completed', 'actions_failed',
        'error_message', 'execution_time_ms',
    ];

    protected function casts(): array
    {
        return [
            'actions_completed' => 'array',
            'actions_failed'    => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn (self $model) => $model->uuid = (string) Str::uuid());
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function triggerable(): MorphTo
    {
        return $this->morphTo();
    }

    public function appendCompletedAction(string $action, mixed $result): void
    {
        $completed = $this->actions_completed ?? [];
        $completed[] = ['action' => $action, 'result' => $result, 'at' => now()->toIso8601String()];
        $this->update(['actions_completed' => $completed]);
    }

    public function appendFailedAction(string $action, string $error): void
    {
        $failed = $this->actions_failed ?? [];
        $failed[] = ['action' => $action, 'error' => $error, 'at' => now()->toIso8601String()];
        $this->update(['actions_failed' => $failed, 'status' => 'partial']);
    }
}
