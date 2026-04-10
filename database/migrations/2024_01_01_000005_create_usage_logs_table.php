<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model_used');
            $table->string('operation_type')->comment('chat, summarize, tag, classify');
            $table->integer('prompt_tokens');
            $table->integer('completion_tokens');
            $table->integer('total_tokens');
            $table->decimal('estimated_cost_usd', 10, 6)->nullable();
            $table->float('latency_ms')->nullable();
            $table->boolean('was_cached')->default(false);
            $table->boolean('was_successful')->default(true);
            $table->string('error_type')->nullable();
            $table->date('usage_date')->index()->comment('Denormalized date for fast aggregation queries');
            $table->timestamps();

            $table->index(['user_id', 'usage_date']);
            $table->index(['user_id', 'operation_type', 'usage_date']);
            $table->index(['usage_date', 'was_successful']);
        });

        // Daily aggregation table for fast reporting
        Schema::create('usage_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('summary_date');
            $table->integer('total_requests')->default(0);
            $table->integer('successful_requests')->default(0);
            $table->integer('failed_requests')->default(0);
            $table->integer('total_prompt_tokens')->default(0);
            $table->integer('total_completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->integer('cached_responses')->default(0);
            $table->decimal('total_cost_usd', 10, 4)->default(0);
            $table->json('breakdown_by_operation')->nullable()->comment('Tokens per operation type');
            $table->timestamps();

            $table->unique(['user_id', 'summary_date']);
            $table->index('summary_date');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['queue', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('usage_daily_summaries');
        Schema::dropIfExists('usage_logs');
    }
};
