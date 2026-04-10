<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'     => User::factory(),
            'name'        => fake()->sentence(3),
            'description' => fake()->sentence(),
            'is_active'   => true,
            'trigger'     => ['event' => 'ticket.created', 'conditions' => []],
            'actions'     => [['type' => 'summarize']],
            'priority'    => 0,
            'run_count'   => 0,
            'success_count' => 0,
            'failure_count' => 0,
        ];
    }
}
