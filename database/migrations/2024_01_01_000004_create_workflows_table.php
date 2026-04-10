<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            // Trigger definition: { "event": "ticket.created", "conditions": [...] }
            $table->json('trigger')->comment('Event type and conditions that fire this workflow');
            // Actions array: [{ "type": "summarize" }, { "type": "tag" }, { "type": "email", "to": "..." }]
            $table->json('actions')->comment('Ordered list of actions to execute');
            $table->integer('run_count')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('failure_count')->default(0);
            $table->timestamp('last_triggered_at')->nullable();
            $table->integer('priority')->default(0)->comment('Higher = runs first when multiple workflows match');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active']);
            $table->index(['is_active', 'priority']);
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('triggerable');  // ticket, conversation, etc.
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'partial'])->default('pending');
            $table->json('actions_completed')->nullable()->comment('Log of completed action results');
            $table->json('actions_failed')->nullable()->comment('Log of failed action details');
            $table->text('error_message')->nullable();
            $table->float('execution_time_ms')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index(['triggerable_type', 'triggerable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflows');
    }
};
