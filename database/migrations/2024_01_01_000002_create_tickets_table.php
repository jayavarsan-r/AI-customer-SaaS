<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->text('description')->nullable();
            $table->enum('status', ['open', 'in_progress', 'waiting', 'resolved', 'closed'])->default('open');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->string('channel')->default('api')->comment('api, email, web, slack');
            $table->string('requester_email')->nullable()->index();
            $table->string('requester_name')->nullable();
            $table->text('ai_summary')->nullable()->comment('LLM-generated summary');
            $table->timestamp('summary_generated_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->integer('message_count')->default(0);
            $table->json('metadata')->nullable()->comment('Channel-specific metadata, external IDs');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'priority']);
            $table->index(['status', 'priority', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->comment('Owning workspace/user');
            $table->string('name');
            $table->string('color', 7)->default('#3B82F6');
            $table->string('category')->nullable()->comment('billing, technical, urgent, etc.');
            $table->boolean('is_ai_generated')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'category']);
        });

        Schema::create('ticket_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_ai_generated')->default(false);
            $table->float('confidence_score')->nullable()->comment('AI confidence 0-1');
            $table->timestamp('created_at');

            $table->unique(['ticket_id', 'tag_id']);
            $table->index(['ticket_id', 'is_ai_generated']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('tickets');
    }
};
