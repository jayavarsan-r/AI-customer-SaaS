<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id'                => Ticket::factory(),
            'user_id'                  => User::factory(),
            'title'                    => fake()->sentence(4),
            'model_used'               => 'claude-sonnet-4-6',
            'total_prompt_tokens'      => 0,
            'total_completion_tokens'  => 0,
            'message_count'            => 0,
            'status'                   => 'active',
        ];
    }
}
