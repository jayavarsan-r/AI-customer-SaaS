<?php

namespace App\Providers;

use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\Providers\AnthropicProvider;
use App\Services\LLM\Providers\OpenAIProvider;
use App\Services\RateLimit\RateLimitService;
use App\Services\Workflow\Actions\EmailAction;
use App\Services\Workflow\Actions\SummarizeAction;
use App\Services\Workflow\Actions\TagAction;
use App\Services\Workflow\Actions\UpdateTicketAction;
use App\Services\Workflow\Actions\WebhookAction;
use App\Services\Workflow\Conditions\ConditionEvaluator;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // =====================================================================
        // LLM Provider — selected via LLM_PROVIDER env var
        // =====================================================================
        $this->app->singleton(LLMProviderInterface::class, function ($app) {
            $provider = config('llm.provider', 'anthropic');

            return match ($provider) {
                'openai'    => new OpenAIProvider(
                    apiKey:       config('llm.openai.api_key'),
                    organization: config('llm.openai.organization'),
                    timeout:      config('llm.timeout', 60),
                ),
                'anthropic' => new AnthropicProvider(
                    apiKey:     config('llm.anthropic.api_key'),
                    apiVersion: config('llm.anthropic.version', '2023-06-01'),
                    baseUrl:    config('llm.anthropic.base_url', 'https://api.anthropic.com'),
                    timeout:    config('llm.timeout', 60),
                ),
                default => throw new \InvalidArgumentException("Unknown LLM provider: {$provider}"),
            };
        });

        // =====================================================================
        // Rate Limit Service — uses dedicated Redis DB
        // =====================================================================
        $this->app->singleton(RateLimitService::class, function ($app) {
            $client = new \Predis\Client([
                'scheme'   => 'tcp',
                'host'     => config('database.redis.default.host', '127.0.0.1'),
                'port'     => config('database.redis.default.port', 6379),
                'password' => config('database.redis.default.password', null),
                'database' => config('database.redis.rate_limit.database', 3),
            ]);

            return new RateLimitService($client);
        });

        // =====================================================================
        // Workflow Engine — wires in all action handlers
        // =====================================================================
        $this->app->singleton(WorkflowEngine::class, function ($app) {
            return new WorkflowEngine(
                conditionEvaluator: $app->make(ConditionEvaluator::class),
                summarizeAction:    $app->make(SummarizeAction::class),
                tagAction:          $app->make(TagAction::class),
                emailAction:        $app->make(EmailAction::class),
                webhookAction:      $app->make(WebhookAction::class),
                updateTicketAction: $app->make(UpdateTicketAction::class),
            );
        });
    }

    public function boot(): void
    {
        // Strict mode in non-production: catch lazy loading, missing columns, etc.
        if (!$this->app->isProduction()) {
            \Illuminate\Database\Eloquent\Model::shouldBeStrict();
        }
    }
}
