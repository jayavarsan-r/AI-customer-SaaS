<?php

namespace App\Jobs;

use App\Models\Tag;
use App\Models\Ticket;
use App\Services\LLM\DTOs\LLMRequest;
use App\Services\LLM\LLMService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Classifies a ticket and applies AI-generated tags.
 *
 * Categories: billing, technical, account, feature_request, urgent, general
 * The LLM returns a JSON structure with tags + confidence scores.
 */
class AutoTagTicket implements ShouldQueue, ShouldBeUnique
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int    $tries = 3;
    public int    $timeout = 45;
    public string $queue = 'default';

    private const VALID_CATEGORIES = [
        'billing', 'technical', 'account', 'feature_request',
        'urgent', 'general', 'bug', 'onboarding',
    ];

    public function __construct(
        public readonly Ticket $ticket,
    ) {}

    public function uniqueId(): string
    {
        return "auto_tag_ticket:{$this->ticket->id}";
    }

    public function handle(LLMService $llm): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $content = $this->buildClassificationContent();

        $request = new LLMRequest(
            messages: [
                [
                    'role'    => 'user',
                    'content' => $content,
                ],
            ],
            systemPrompt: $this->buildSystemPrompt(),
            maxTokens:    256,
            temperature:  0.0, // Deterministic classification
            operationType: 'tag',
            useCache:     true,
        );

        $response = $llm->complete(
            $request,
            userId:   $this->ticket->user_id,
            ticketId: $this->ticket->id,
        );

        $classifications = $this->parseClassifications($response->content);

        if (empty($classifications)) {
            Log::warning("AutoTagTicket: could not parse classifications", [
                'ticket_id' => $this->ticket->id,
                'response'  => $response->content,
            ]);
            return;
        }

        $this->applyTags($classifications);

        Log::info("AutoTagTicket completed", [
            'ticket_id' => $this->ticket->id,
            'tags'      => array_column($classifications, 'category'),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("AutoTagTicket permanently failed", [
            'ticket_id' => $this->ticket->id,
            'error'     => $exception->getMessage(),
        ]);
    }

    private function buildClassificationContent(): string
    {
        return <<<CONTENT
Classify this support ticket into categories.

Subject: {$this->ticket->subject}
Description: {$this->ticket->description}
Priority: {$this->ticket->priority}

Return ONLY valid JSON in this format:
{
  "tags": [
    {"category": "billing", "confidence": 0.92},
    {"category": "urgent", "confidence": 0.85}
  ]
}

Valid categories: billing, technical, account, feature_request, urgent, general, bug, onboarding
Only include tags with confidence > 0.6. Maximum 3 tags.
CONTENT;
    }

    private function buildSystemPrompt(): string
    {
        return 'You are a ticket classification system. Respond ONLY with valid JSON. No explanation, no markdown, no code blocks — just raw JSON.';
    }

    private function parseClassifications(string $content): array
    {
        // Strip any markdown code fences if LLM added them
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', trim($content));

        try {
            $data = json_decode($cleaned, true, 10, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!isset($data['tags']) || !is_array($data['tags'])) {
            return [];
        }

        return array_filter($data['tags'], function (array $tag) {
            return isset($tag['category'], $tag['confidence'])
                && in_array($tag['category'], self::VALID_CATEGORIES, true)
                && is_numeric($tag['confidence'])
                && $tag['confidence'] > 0.6;
        });
    }

    private function applyTags(array $classifications): void
    {
        $syncData = [];

        foreach ($classifications as $classification) {
            $tag = Tag::firstOrCreate(
                [
                    'user_id'  => $this->ticket->user_id,
                    'name'     => $classification['category'],
                ],
                [
                    'category'         => $classification['category'],
                    'is_ai_generated'  => true,
                    'color'            => $this->getCategoryColor($classification['category']),
                ]
            );

            $syncData[$tag->id] = [
                'is_ai_generated' => true,
                'confidence_score' => round($classification['confidence'], 4),
                'created_at'      => now(),
            ];
        }

        // Use sync to avoid duplicates; existing tags not in new set will be detached
        $this->ticket->tags()->syncWithoutDetaching($syncData);
    }

    private function getCategoryColor(string $category): string
    {
        return match ($category) {
            'billing'         => '#EF4444',
            'technical'       => '#3B82F6',
            'urgent'          => '#F97316',
            'bug'             => '#DC2626',
            'feature_request' => '#8B5CF6',
            'account'         => '#10B981',
            'onboarding'      => '#06B6D4',
            default           => '#6B7280',
        };
    }
}
