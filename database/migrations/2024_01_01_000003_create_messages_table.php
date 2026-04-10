<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('model_used')->nullable()->comment('LLM model identifier');
            $table->integer('total_prompt_tokens')->default(0);
            $table->integer('total_completion_tokens')->default(0);
            $table->integer('message_count')->default(0);
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->json('system_prompt')->nullable()->comment('System prompt context');
            $table->timestamps();

            $table->index(['ticket_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant', 'system'])->index();
            $table->longText('content');
            $table->integer('prompt_tokens')->default(0)->comment('Tokens in this message prompt');
            $table->integer('completion_tokens')->default(0)->comment('Tokens in this message completion');
            $table->integer('total_tokens')->default(0);
            $table->string('model_used')->nullable();
            $table->float('processing_time_ms')->nullable()->comment('LLM response time in ms');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('failure_reason')->nullable();
            $table->integer('retry_count')->default(0);
            $table->boolean('is_cached')->default(false)->comment('Response served from cache');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['ticket_id', 'role']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
