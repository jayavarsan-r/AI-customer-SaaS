<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'                 => fake()->name(),
            'email'                => fake()->unique()->safeEmail(),
            'email_verified_at'    => now(),
            'password'             => Hash::make('password'),
            'company_name'         => fake()->company(),
            'plan'                 => 'free',
            'is_active'            => true,
            'is_admin'             => false,
            'monthly_token_quota'  => 100_000,
            'daily_token_quota'    => 10_000,
            'remember_token'       => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['is_admin' => true]);
    }

    public function pro(): static
    {
        return $this->state(fn () => [
            'plan'                => 'pro',
            'monthly_token_quota' => 2_000_000,
            'daily_token_quota'   => 200_000,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
