<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workflow_id'      => Workflow::factory(),
            'user_id'          => User::factory(),
            'triggerable_type' => \App\Models\Ticket::class,
            'triggerable_id'   => \App\Models\Ticket::factory(),
            'status'           => 'pending',
        ];
    }
}
