<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'subject'         => fake()->sentence(6),
            'description'     => fake()->paragraph(),
            'status'          => fake()->randomElement(['open', 'in_progress', 'waiting', 'resolved']),
            'priority'        => fake()->randomElement(['low', 'normal', 'high', 'urgent']),
            'channel'         => 'api',
            'requester_email' => fake()->safeEmail(),
            'requester_name'  => fake()->name(),
            'message_count'   => 0,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => 'open']);
    }

    public function urgent(): static
    {
        return $this->state(fn () => ['priority' => 'urgent', 'status' => 'open']);
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => 'resolved', 'resolved_at' => now()]);
    }
}
