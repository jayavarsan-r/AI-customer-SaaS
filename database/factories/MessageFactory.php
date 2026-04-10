<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id'    => Conversation::factory(),
            'ticket_id'          => Ticket::factory(),
            'user_id'            => User::factory(),
            'role'               => fake()->randomElement(['user', 'assistant']),
            'content'            => fake()->paragraph(),
            'prompt_tokens'      => fake()->numberBetween(50, 500),
            'completion_tokens'  => fake()->numberBetween(20, 300),
            'total_tokens'       => fake()->numberBetween(70, 800),
            'model_used'         => 'claude-sonnet-4-6',
            'processing_time_ms' => fake()->randomFloat(1, 100, 5000),
            'status'             => 'completed',
            'is_cached'          => false,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending', 'role' => 'user']);
    }

    public function assistant(): static
    {
        return $this->state(fn () => ['role' => 'assistant', 'status' => 'completed']);
    }
}
