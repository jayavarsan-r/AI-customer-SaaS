<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'company_name',
        'plan', 'is_active', 'is_admin', 'settings',
        'monthly_token_quota', 'daily_token_quota',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_admin' => 'boolean',
            'settings' => 'array',
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

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }

    public function usageDailySummaries(): HasMany
    {
        return $this->hasMany(UsageDailySummary::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    // -------------------------------------------------------------------------
    // Business Logic
    // -------------------------------------------------------------------------

    public function getRemainingDailyTokens(): int
    {
        $used = $this->usageLogs()
            ->whereDate('usage_date', today())
            ->sum('total_tokens');

        return max(0, $this->daily_token_quota - $used);
    }

    public function getRemainingMonthlyTokens(): int
    {
        $used = $this->usageLogs()
            ->whereMonth('usage_date', now()->month)
            ->whereYear('usage_date', now()->year)
            ->sum('total_tokens');

        return max(0, $this->monthly_token_quota - $used);
    }

    public function hasExceededDailyTokenQuota(): bool
    {
        return $this->getRemainingDailyTokens() <= 0;
    }

    public function getPlanLimits(): array
    {
        return match ($this->plan) {
            'free'       => ['rpm' => 10, 'daily_tokens' => 10_000, 'monthly_tokens' => 100_000],
            'starter'    => ['rpm' => 30, 'daily_tokens' => 50_000, 'monthly_tokens' => 500_000],
            'pro'        => ['rpm' => 60, 'daily_tokens' => 200_000, 'monthly_tokens' => 2_000_000],
            'enterprise' => ['rpm' => 300, 'daily_tokens' => 1_000_000, 'monthly_tokens' => 20_000_000],
            default      => ['rpm' => 10, 'daily_tokens' => 10_000, 'monthly_tokens' => 100_000],
        };
    }
}
