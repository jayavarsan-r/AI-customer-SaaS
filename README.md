# AI Customer Support & Automation SaaS

A **production-grade Laravel 11 backend** for an AI-powered customer support platform. Built with real-world SaaS engineering practices: async queue processing, Redis-based rate limiting, LLM integration with retries and caching, a fully programmable workflow automation engine, and comprehensive observability APIs.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Project Structure](#project-structure)
3. [Tech Stack](#tech-stack)
4. [Database Design](#database-design)
5. [API Reference](#api-reference)
6. [Queue System](#queue-system)
7. [LLM Integration](#llm-integration)
8. [Rate Limiting](#rate-limiting)
9. [Workflow Engine](#workflow-engine)
10. [Admin & Monitoring](#admin--monitoring)
11. [Setup & Installation](#setup--installation)
12. [Running Queue Workers](#running-queue-workers)
13. [Testing](#testing)
14. [Scaling Strategy](#scaling-strategy)
15. [Edge Case Handling](#edge-case-handling)

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                         API Layer (Laravel)                         │
│   Auth  │  Tickets  │  Chat  │  Workflows  │  Admin                │
└───────────────────────────┬─────────────────────────────────────────┘
                            │
          ┌─────────────────▼──────────────────┐
          │         Middleware Stack            │
          │  Sanctum Auth → RPM Limiter →       │
          │  Token Quota → Admin Guard          │
          └─────────────────┬──────────────────┘
                            │
          ┌─────────────────▼──────────────────┐
          │        Service Layer                │
          │  LLMService  │  WorkflowEngine      │
          │  RateLimitService                   │
          └───────┬──────────────┬─────────────┘
                  │              │
        ┌─────────▼──────┐  ┌───▼──────────────────┐
        │   Redis         │  │  MySQL               │
        │  - Queues       │  │  - Users             │
        │  - Cache        │  │  - Tickets           │
        │  - Rate Limits  │  │  - Messages          │
        └────────────────┘  │  - Conversations     │
                            │  - Workflows         │
                            │  - UsageLogs         │
                            └──────────────────────┘
                            
        Queue Workers (Laravel Horizon / supervisord)
        ┌────────────┐  ┌─────────────┐  ┌─────────────┐
        │  high      │  │  default    │  │  low        │
        │  Chat jobs │  │  Workflows  │  │  Cleanup    │
        │  (fast)    │  │  Tags/Summ. │  │  Pruning    │
        └────────────┘  └─────────────┘  └─────────────┘
```

### Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| **Async chat via queues** | API returns 202 immediately; LLM latency (1-5s) handled in background. Client subscribes via WebSocket for the response. |
| **Streaming fallback** | `?stream=true` parameter returns Server-Sent Events for real-time UX without queue overhead |
| **Three queue priorities** | `high` for real-time chat, `default` for workflows/summarization, `low` for cleanup — prevents background work from starving user-facing jobs |
| **Redis sliding window** | More accurate than fixed-window for rate limiting; prevents burst traffic gaming the counter reset |
| **LLM response cache** | SHA-256 hash of `{messages + model + systemPrompt}` → cached in Redis. Identical requests (e.g., re-summarizing unchanged tickets) hit cache instead of billing |
| **Workflow partial execution** | Individual action failures are logged but don't halt subsequent actions; `WorkflowRun.status` is `partial` not `failed` |
| **Soft deletes everywhere** | Tickets and users are never hard-deleted; audit trail preserved |
| **Strict Eloquent mode** | Enabled in non-production to catch N+1 queries and missing column accesses during development |

---

## Project Structure

```
app/
├── Console/Commands/
│   └── RebuildDailyUsageSummaries.php   # Scheduled aggregation job
│
├── Events/
│   ├── TicketCreated.php                # Fired after ticket creation → triggers workflows
│   └── MessageCompleted.php            # Fired after AI responds → WebSocket broadcast
│
├── Http/
│   ├── Controllers/Api/V1/
│   │   ├── Auth/AuthController.php     # Register, login, logout, /me
│   │   ├── TicketController.php        # CRUD + manual summarize/tag
│   │   ├── ChatController.php          # Send/list messages, streaming
│   │   ├── WorkflowController.php      # CRUD + test + run history
│   │   └── Admin/AdminController.php   # Health, failed jobs, usage stats
│   │
│   └── Middleware/
│       ├── ApiRateLimitMiddleware.php  # Redis sliding-window RPM limiter
│       ├── TokenQuotaMiddleware.php    # Daily token quota enforcement
│       └── AdminMiddleware.php        # Admin flag / API key guard
│
├── Jobs/
│   ├── ProcessChatMessage.php          # Async LLM chat → stores AI response
│   ├── SummarizeTicket.php             # LLM ticket summarization
│   ├── AutoTagTicket.php               # LLM classification → tags
│   └── ExecuteWorkflow.php             # Runs a workflow against a ticket
│
├── Listeners/
│   └── TriggerWorkflowsOnTicketCreated.php
│
├── Models/
│   ├── User.php
│   ├── Ticket.php
│   ├── Conversation.php
│   ├── Message.php
│   ├── Workflow.php
│   ├── WorkflowRun.php
│   ├── Tag.php
│   ├── UsageLog.php
│   └── UsageDailySummary.php
│
├── Providers/
│   ├── AppServiceProvider.php          # DI bindings: LLM, RateLimit, Workflow
│   └── EventServiceProvider.php       # Event → Listener mappings
│
└── Services/
    ├── LLM/
    │   ├── Contracts/LLMProviderInterface.php
    │   ├── DTOs/LLMRequest.php
    │   ├── DTOs/LLMResponse.php
    │   ├── Exceptions/
    │   │   ├── LLMException.php
    │   │   ├── LLMRateLimitException.php
    │   │   └── LLMTimeoutException.php
    │   ├── Providers/
    │   │   ├── AnthropicProvider.php
    │   │   └── OpenAIProvider.php
    │   └── LLMService.php              # Cache + retry + usage logging orchestrator
    │
    ├── RateLimit/
    │   ├── RateLimitService.php        # Redis sliding-window implementation
    │   └── RateLimitResult.php         # Value object
    │
    └── Workflow/
        ├── Actions/
        │   ├── ActionInterface.php
        │   ├── SummarizeAction.php
        │   ├── TagAction.php
        │   ├── EmailAction.php
        │   ├── WebhookAction.php
        │   └── UpdateTicketAction.php
        ├── Conditions/
        │   └── ConditionEvaluator.php
        └── WorkflowEngine.php
```

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Framework | Laravel 11 |
| Language | PHP 8.2+ |
| Database | MySQL 8.0+ |
| Cache / Queue / Rate Limiting | Redis 7+ |
| Authentication | Laravel Sanctum (Bearer tokens) |
| LLM APIs | Anthropic Claude / OpenAI GPT-4o |
| HTTP Client | Guzzle 7 |
| Testing | PHPUnit 11 + Mockery |
| Queue Monitor | Laravel Horizon (recommended) |
| Process Manager | supervisord |

---

## Database Design

### Entity Relationship Diagram

```
users ─────────────────────────────────────────────────────┐
  │ id, uuid, name, email, plan, daily_token_quota, ...     │
  │                                                          │
  ├──< tickets >──────────────────────────────────┐         │
  │     id, uuid, user_id, subject, status,        │         │
  │     priority, ai_summary, message_count        │         │
  │                                                │         │
  │     ├──< conversations >──────────────────────┤         │
  │     │     id, uuid, ticket_id, user_id,        │         │
  │     │     total_tokens, model_used             │         │
  │     │                                          │         │
  │     │     └──< messages >                      │         │
  │     │           id, uuid, conversation_id,     │         │
  │     │           role, content, status,         │         │
  │     │           prompt_tokens, completion_tokens│         │
  │     │                                          │         │
  │     └──< ticket_tag >──< tags >                │         │
  │           confidence_score, is_ai_generated    │         │
  │                                                │         │
  ├──< workflows >                                 │         │
  │     id, uuid, trigger (JSON), actions (JSON),  │         │
  │     is_active, priority, run_count             │         │
  │                                                │         │
  │     └──< workflow_runs >──────────────────────┘         │
  │           triggerable (morph), status,                   │
  │           actions_completed, actions_failed              │
  │                                                          │
  └──< usage_logs >                                          │
        user_id, model_used, operation_type,                 │
        prompt_tokens, completion_tokens, usage_date         │
                                                             │
  └──< usage_daily_summaries >───────────────────────────────┘
        user_id, summary_date, total_tokens, total_cost_usd
```

### Key Schema Choices

- **`usage_date` column** — denormalized date on `usage_logs` enables fast aggregation without expensive `DATE()` function calls on `created_at`
- **`trigger` and `actions` as JSON** — workflows are polymorphic by design; adding new trigger events or action types requires no schema changes
- **`workflow_runs.triggerable` morph** — workflows can be triggered by tickets, conversations, or any future model without schema changes
- **`usage_daily_summaries`** — pre-aggregated table rebuilt nightly; admin reporting queries read this, not `usage_logs` (which can have millions of rows)
- **Soft deletes** on `users` and `tickets` — GDPR compliance and audit trail

---

## API Reference

### Base URL
```
https://api.yourdomain.com/api/v1
```

### Authentication
All protected endpoints require:
```
Authorization: Bearer {sanctum_token}
```

---

### Auth Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/auth/register` | Create account |
| `POST` | `/auth/login` | Get access token |
| `DELETE` | `/auth/logout` | Revoke current token |
| `GET` | `/auth/me` | Current user + quota info |

**POST /auth/register**
```json
// Request
{
  "name": "Jane Smith",
  "email": "jane@acme.com",
  "password": "secret123",
  "password_confirmation": "secret123",
  "company_name": "Acme Corp"
}

// Response 201
{
  "user": { "id": "uuid", "name": "Jane Smith", "plan": "free" },
  "token": "1|abc123..."
}
```

---

### Ticket Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/tickets` | List tickets (paginated, filterable) |
| `POST` | `/tickets` | Create ticket → fires TicketCreated event |
| `GET` | `/tickets/{uuid}` | Get single ticket with tags + conversations |
| `PATCH` | `/tickets/{uuid}` | Update status/priority |
| `DELETE` | `/tickets/{uuid}` | Soft delete |
| `POST` | `/tickets/{uuid}/summarize` | Queue AI summarization |
| `POST` | `/tickets/{uuid}/tag` | Queue auto-tagging |

**POST /tickets**
```json
// Request
{
  "subject": "Payment declined on Pro plan upgrade",
  "description": "I tried to upgrade but my card keeps getting declined...",
  "priority": "high",
  "channel": "api",
  "requester_email": "customer@example.com"
}

// Response 201
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "subject": "Payment declined on Pro plan upgrade",
  "status": "open",
  "priority": "high",
  "ai_summary": null,
  "tags": [],
  "created_at": "2026-04-10T12:00:00Z"
}
```

**Query Parameters for GET /tickets:**
- `status` — `open | in_progress | waiting | resolved | closed`
- `priority` — `low | normal | high | urgent`
- `search` — full-text search on subject + description

---

### Chat Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/tickets/{uuid}/messages` | Send a message (async by default) |
| `GET` | `/tickets/{uuid}/messages` | List conversation messages |
| `POST` | `/tickets/{uuid}/conversations` | Start a new conversation (reset context) |

**POST /tickets/{uuid}/messages** (Async mode)
```json
// Request
{ "content": "Why was my card declined?" }

// Response 202 — queued for async processing
{
  "message": {
    "id": "msg-uuid",
    "role": "user",
    "content": "Why was my card declined?",
    "status": "pending",
    "created_at": "2026-04-10T12:00:01Z"
  },
  "meta": {
    "async": true,
    "conversation_id": "conv-uuid",
    "note": "AI response delivered via WebSocket: ticket.{id}"
  }
}
```

**POST /tickets/{uuid}/messages?stream=true** (SSE Streaming mode)
```
Content-Type: text/event-stream

data: {"chunk": "Your payment failed because "}
data: {"chunk": "the card issuer declined the"}
data: {"chunk": " charge. Common reasons include..."}
data: {"done": true, "message_id": "uuid", "total_tokens": 234}
```

---

### Workflow Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/workflows` | List user's workflows |
| `POST` | `/workflows` | Create workflow |
| `GET` | `/workflows/{uuid}` | Get workflow + recent runs |
| `PATCH` | `/workflows/{uuid}` | Update workflow |
| `DELETE` | `/workflows/{uuid}` | Delete workflow |
| `POST` | `/workflows/{uuid}/test` | Manually trigger against a ticket |
| `GET` | `/workflows/{uuid}/runs` | Paginated run history |

**POST /workflows — Example: Auto-process urgent billing tickets**
```json
{
  "name": "Urgent billing → escalate",
  "trigger": {
    "event": "ticket.created",
    "conditions": [
      { "field": "priority", "operator": "equals", "value": "urgent" },
      { "field": "subject", "operator": "contains", "value": "billing" }
    ]
  },
  "actions": [
    { "type": "summarize" },
    { "type": "tag" },
    { "type": "update_ticket", "params": { "status": "in_progress" } },
    {
      "type": "email",
      "params": {
        "to": "billing-lead@company.com",
        "subject": "URGENT: New billing ticket requires attention"
      }
    }
  ],
  "priority": 10
}
```

**Supported trigger events:**
- `ticket.created`
- `ticket.updated`
- `ticket.resolved`
- `message.created`

**Supported condition operators:**
- `equals`, `not_equals`
- `contains`, `starts_with`
- `in`, `not_in`

**Supported action types:**
| Type | Description | Params |
|------|-------------|--------|
| `summarize` | Queues AI summarization | none |
| `tag` | Queues AI auto-tagging | none |
| `email` | Sends email notification | `to`, `subject`, `body` |
| `webhook` | HTTP POST to external URL | `url`, `method`, `headers`, `payload` |
| `update_ticket` | Updates ticket fields | `status`, `priority` |

---

### Admin Endpoints

> Requires `is_admin: true` or `X-Admin-Key: {ADMIN_API_KEY}` header.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/admin/health` | DB, Redis, queue health check |
| `GET` | `/admin/queue-stats` | Queue depths per priority |
| `GET` | `/admin/failed-jobs` | Paginated failed job list |
| `POST` | `/admin/failed-jobs/{uuid}/retry` | Re-queue a failed job |
| `GET` | `/admin/usage-stats?days=30` | Token usage analytics |
| `GET` | `/admin/users` | User list with usage summaries |
| `GET` | `/ping` | Load balancer health ping (no auth) |

**GET /admin/health**
```json
{
  "status": "healthy",
  "timestamp": "2026-04-10T12:00:00Z",
  "checks": {
    "database": { "status": "ok" },
    "redis": { "status": "ok", "version": "7.2.0", "used_memory": "52.3M" },
    "queues": { "high": 0, "default": 3, "low": 0 }
  }
}
```

**GET /admin/usage-stats**
```json
{
  "period_days": 30,
  "totals": {
    "tokens": 4823901,
    "requests": 12483,
    "cost_usd": 14.47
  },
  "daily_stats": [...],
  "top_users": [...],
  "by_operation": [
    { "operation_type": "chat", "total_tokens": 3200000, "count": 9800 },
    { "operation_type": "summarize", "total_tokens": 1100000, "count": 2100 },
    { "operation_type": "tag", "total_tokens": 523901, "count": 583 }
  ]
}
```

### Standard Response Headers

Every API response includes:
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1712750460
X-Token-Quota-Limit: 200000
X-Token-Quota-Remaining: 186432
```

---

## Queue System

### Queue Architecture

```
User sends message → ProcessChatMessage dispatched to [high] queue
                              │
                   ┌──────────▼───────────┐
                   │   LLM API Call        │
                   │   (1–5 seconds)       │
                   └──────────┬───────────┘
                              │
              ┌───────────────▼────────────────┐
              │   AI response stored           │
              │   MessageCompleted event fired │
              │   WebSocket broadcast          │
              └────────────────────────────────┘

Ticket created → TicketCreated event → TriggerWorkflowsOnTicketCreated
                              │
              ┌───────────────▼──────────────────────────┐
              │  Matching workflows queried from DB       │
              │  ExecuteWorkflow dispatched for each      │
              └───────────────┬──────────────────────────┘
                              │
              ┌───────────────▼──────────────────────────┐
              │  WorkflowEngine executes actions          │
              │  → SummarizeTicket dispatched [default]   │
              │  → AutoTagTicket dispatched [default]     │
              │  → EmailAction runs inline               │
              └──────────────────────────────────────────┘
```

### Job Classes

| Job | Queue | Tries | Timeout | Unique |
|-----|-------|-------|---------|--------|
| `ProcessChatMessage` | high | 3 | 90s | Per message |
| `SummarizeTicket` | default | 3 | 60s | Per ticket |
| `AutoTagTicket` | default | 3 | 45s | Per ticket |
| `ExecuteWorkflow` | default | 2 | 120s | No |

### Retry Strategy

```php
// Rate limit hit → release with provider's retry-after delay
$this->release($e->retryAfterSeconds);

// Timeout → exponential backoff
$delay = BASE_DELAY_MS * (2 ** $attempt);

// Non-retryable (auth, bad request) → fail immediately
throw $e; // No release
```

### Failed Job Handling

Failed jobs are stored in the `failed_jobs` table with full exception traces. Use the admin API to list and retry them:

```bash
# Via CLI
php artisan queue:retry all
php artisan queue:retry {uuid}

# Via API
POST /api/v1/admin/failed-jobs/{uuid}/retry
```

---

## LLM Integration

### Provider Abstraction

```
LLMService (orchestrator)
    ↓ cache check → hit? return cached LLMResponse
    ↓ executeWithRetry()
        ↓ LLMProviderInterface
            ├── AnthropicProvider   (claude-sonnet-4-6 default)
            └── OpenAIProvider      (gpt-4o fallback)
    ↓ store in cache
    ↓ log usage to DB
    → return LLMResponse DTO
```

### LLM Request/Response DTOs

```php
// Request
new LLMRequest(
    messages:      [['role' => 'user', 'content' => '...']],
    systemPrompt:  'You are a helpful assistant...',
    model:         'claude-sonnet-4-6',
    maxTokens:     2048,
    temperature:   0.3,
    operationType: 'chat',
    useCache:      false,
);

// Response
$response->content;           // string
$response->model;             // 'claude-sonnet-4-6'
$response->promptTokens;      // int
$response->completionTokens;  // int
$response->totalTokens;       // int
$response->latencyMs;         // float
$response->fromCache;         // bool
```

### Response Cache

Cache key = `SHA-256(messages + systemPrompt + model + maxTokens + temperature)`

Same classification request for similar tickets will hit cache, eliminating redundant API calls. TTL is configurable (`LLM_CACHE_TTL=3600`).

### Supported Models

| Alias | Model ID | Best For |
|-------|----------|----------|
| `fast` | claude-haiku-4-5-20251001 | Auto-tagging, quick classification |
| `default` | claude-sonnet-4-6 | Chat, summarization (balanced) |
| `premium` | claude-opus-4-6 | Complex analysis, enterprise |

### Cost Estimation

Costs are estimated per request and stored in `usage_logs.estimated_cost_usd`:

```php
// Per 1M tokens (as of April 2026):
claude-sonnet-4-6:  $3.00 input / $15.00 output
claude-haiku-4-5:   $0.25 input / $1.25  output
claude-opus-4-6:    $15.00 input / $75.00 output
gpt-4o:             $2.50 input / $10.00  output
```

---

## Rate Limiting

### Implementation: Redis Sliding Window

```
Timeline:  |----60 seconds window----|
           t=0   t=10  t=20  t=30  t=40  t=50  t=60

Requests:   R1    R2    R3    R4          R5    R6(?)

At t=60: window = [t=0..t=60], count = 5 requests
At t=61: window = [t=1..t=61], R1 drops out, count = 4

Redis key: rl:rpm:{userId}     (sorted set of timestamps)
Redis key: rl:tpm:{userId}     (counter, 60s TTL)
Redis key: rl:tpd:{userId}     (counter, 86400s TTL)
Redis key: rl:tpd uses DB 3    (isolated from cache DB 1 and queue DB 2)
```

### Plan Limits

| Plan | RPM | Daily Tokens | Monthly Tokens |
|------|-----|-------------|----------------|
| Free | 10 | 10,000 | 100,000 |
| Starter | 30 | 50,000 | 500,000 |
| Pro | 60 | 200,000 | 2,000,000 |
| Enterprise | 300 | 1,000,000 | 20,000,000 |

### Rate Limit Exceeded Response (429)
```json
{
  "error": "Too many requests.",
  "limit_type": "requests_per_minute",
  "limit": 60,
  "retry_after": 23
}
```

---

## Workflow Engine

### How Workflows Are Stored

Workflows are stored as JSON documents in MySQL:

```json
{
  "trigger": {
    "event": "ticket.created",
    "conditions": [
      { "field": "priority", "operator": "equals", "value": "urgent" },
      { "field": "subject", "operator": "contains", "value": "billing" }
    ]
  },
  "actions": [
    { "type": "summarize" },
    { "type": "tag" },
    { "type": "update_ticket", "params": { "status": "in_progress" } },
    {
      "type": "webhook",
      "params": {
        "url": "https://hooks.slack.com/...",
        "payload": { "text": "New urgent billing ticket!" }
      }
    }
  ]
}
```

### Execution Flow

```
TicketCreated event fires
        │
        ▼
TriggerWorkflowsOnTicketCreated (queued listener)
        │
        ├── Workflow::forEvent('ticket.created')
        │       WHERE user_id = ? AND is_active = 1
        │       ORDER BY priority DESC
        │
        ├── Conditions pre-evaluated (fast path)
        │
        └── ExecuteWorkflow::dispatch() for each match
                │
                ▼
            WorkflowEngine::execute()
                │
                ├── Re-evaluate conditions (state may have changed)
                │
                ├── Action 1: SummarizeAction → SummarizeTicket::dispatch()
                ├── Action 2: TagAction → AutoTagTicket::dispatch()
                ├── Action 3: UpdateTicketAction → ticket->update()
                └── Action 4: EmailAction → Mail::raw()
                
                Each action result → logged to WorkflowRun.actions_completed
                Failed actions     → logged to WorkflowRun.actions_failed (non-fatal)
```

### Adding a New Action Type

1. Create `app/Services/Workflow/Actions/MyNewAction.php` implementing `ActionInterface`
2. Add it to `WorkflowEngine::$actionRegistry` in the constructor
3. Bind in `AppServiceProvider`
4. Add `my_new_action` to the validation `in:` rule in `WorkflowController::store()`

---

## Admin & Monitoring

### Health Check

The `/admin/health` endpoint is designed for:
- **Load balancer health checks** (use `/ping` for no-auth version)
- **Uptime monitoring** (PagerDuty, Datadog, etc.)
- **Dashboard status pages**

### Observability Stack (Recommended)

```
Application → Laravel Telescope (dev)
Application → Laravel Horizon (queue monitoring)
Application → Datadog / New Relic APM
MySQL → Slow query log → Percona Monitoring
Redis → RedisInsight / redis-cli MONITOR
Logs → CloudWatch / Papertrail
```

---

## Setup & Installation

### Prerequisites

- PHP 8.2+
- MySQL 8.0+
- Redis 7+
- Composer

### 1. Clone and Install

```bash
git clone https://github.com/your-org/ai-support-saas.git
cd ai-support-saas
composer install --optimize-autoloader --no-dev
```

### 2. Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env`:
```env
DB_DATABASE=ai_support_saas
DB_USERNAME=your_user
DB_PASSWORD=your_password

REDIS_HOST=127.0.0.1

# Pick your LLM provider
LLM_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-...

# Or OpenAI
LLM_PROVIDER=openai
OPENAI_API_KEY=sk-...
```

### 3. Database

```bash
mysql -u root -e "CREATE DATABASE ai_support_saas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate
```

### 4. Cache & Optimize

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

### 5. Start the Development Server

```bash
php artisan serve
```

---

## Running Queue Workers

### Development (single worker)

```bash
php artisan queue:work redis --queue=high,default,low --tries=3 --timeout=90
```

### Production (supervisord)

```ini
; /etc/supervisor/conf.d/ai-support.conf

[program:queue-high]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/ai-support/artisan queue:work redis --queue=high --tries=3 --timeout=90 --sleep=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/queue-high.log

[program:queue-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/ai-support/artisan queue:work redis --queue=default --tries=3 --timeout=120 --sleep=2
autostart=true
autorestart=true
numprocs=2
stdout_logfile=/var/log/supervisor/queue-default.log

[program:queue-low]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/ai-support/artisan queue:work redis --queue=low --tries=2 --timeout=60 --sleep=5
autostart=true
autorestart=true
numprocs=1
stdout_logfile=/var/log/supervisor/queue-low.log
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl start all
```

### Laravel Horizon (recommended for production)

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan horizon
```

Configure `config/horizon.php` to match queue priority setup above.

### Scheduler (cron)

```bash
# /etc/cron.d/ai-support
* * * * * www-data php /var/www/ai-support/artisan schedule:run >> /dev/null 2>&1
```

Scheduled jobs:
- `01:00` daily — Rebuild usage daily summaries
- `02:00` Sunday — Prune usage logs older than 90 days

---

## Testing

### Run All Tests

```bash
php artisan test
# or
./vendor/bin/phpunit
```

### Run by Suite

```bash
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
```

### Run Specific Test

```bash
php artisan test tests/Feature/Api/TicketApiTest.php
php artisan test --filter=test_can_create_ticket
```

### Test Coverage

```bash
./vendor/bin/phpunit --coverage-html storage/coverage
```

### Test Strategy

| Layer | Approach | Tools |
|-------|----------|-------|
| LLM Service | Mock `LLMProviderInterface`, test cache/retry logic | PHPUnit + Mockery |
| Jobs | Run jobs synchronously (`QUEUE_CONNECTION=sync`), mock LLM provider | PHPUnit + `Queue::fake()` |
| Workflow Engine | Full integration with real DB + fake queues | PHPUnit |
| API Controllers | Full HTTP request/response cycle, SQLite in-memory | PHPUnit + `RefreshDatabase` |
| Middleware | Assert 429 responses, rate limit header values | PHPUnit |

---

## Scaling Strategy

### Horizontal Scaling

```
                    ┌─────────────────┐
                    │   Load Balancer  │
                    │  (AWS ALB/nginx) │
                    └────────┬────────┘
                             │
              ┌──────────────┼──────────────┐
              │              │              │
        ┌─────▼─────┐  ┌─────▼─────┐  ┌───▼──────┐
        │ App Node 1 │  │ App Node 2 │  │ App Node 3│
        │ (PHP-FPM)  │  │ (PHP-FPM)  │  │ (PHP-FPM) │
        └─────┬──────┘  └─────┬──────┘  └───┬───────┘
              │               │              │
              └───────────────┼──────────────┘
                              │
                    ┌─────────▼────────┐
                    │   MySQL (RDS)    │
                    │   Primary + Read  │
                    │   Replicas       │
                    └──────────────────┘
                              │
                    ┌─────────▼────────┐
                    │   Redis Cluster  │
                    │  (ElastiCache)   │
                    └──────────────────┘
```

**Key**: All app nodes are stateless. Session stored in Redis. No local file dependencies.

### Queue Worker Scaling

Scale workers independently from web nodes:

```bash
# High priority workers: scale up during business hours
numprocs=8  # chat is latency-sensitive

# Default priority workers: scale based on ticket volume
numprocs=4

# Low priority: always minimal
numprocs=1
```

### Database Optimization

```sql
-- Reads for listing/filtering use these composite indexes:
-- tickets: (user_id, status), (status, priority, created_at)
-- usage_logs: (user_id, usage_date), (user_id, operation_type, usage_date)
-- messages: (conversation_id, created_at)

-- Reporting queries hit usage_daily_summaries (pre-aggregated)
-- NOT usage_logs (which can have millions of rows)
```

### Caching Strategy

| Data | Cache | TTL |
|------|-------|-----|
| LLM responses (same prompt) | Redis | 1 hour |
| User plan limits | Redis | 5 minutes |
| Admin usage stats | Redis | 10 minutes |
| Rate limit counters | Redis | 60s / 86400s |

---

## Edge Case Handling

### LLM API Failures

| Scenario | Handling |
|----------|----------|
| Rate limit (429) | Release job back to queue with `retry-after` delay |
| Timeout | Exponential backoff: 1s, 2s, 4s |
| Server error (5xx) | Retry 3 times, then mark job as permanently failed |
| Auth error (401) | Fail immediately, log alert |
| Context too long | Truncate to last 20 messages via `getContextWindow(20)` |

### Duplicate Job Prevention

- `ShouldBeUnique` on `ProcessChatMessage` → uniqueId = `chat_message:{id}`
- `ShouldBeUnique` on `SummarizeTicket` → uniqueId = `summarize_ticket:{id}`
- Prevents double AI responses if a job is accidentally dispatched twice

### Workflow Condition Re-evaluation

Conditions are evaluated **twice**:
1. Before dispatching `ExecuteWorkflow` job (fast path to avoid unnecessary job creation)
2. Inside `WorkflowEngine::execute()` at run time (state may have changed since dispatch)

### Queue Stuck Detection

The scheduler runs `queue:retry all` every 5 minutes to re-queue jobs stuck in `processing` state beyond their timeout (can happen during worker crashes).

### Token Quota Races

Token quota checks use Redis INCRBY which is atomic. Multiple concurrent requests cannot both "see" quota as available and both proceed — Redis INCRBY is linearizable.

### Cascading LLM Cost

If a user's workflow triggers on every ticket and includes both `summarize` + `tag` actions, costs multiply. Mitigation:
- Per-user daily token quotas enforced at the middleware AND at the rate limiter
- Workflow actions that call LLM jobs respect the same token quota check inside the job
- Admin can inspect `usage_stats` to identify cost spikes per user

---

## Response Codes

| Code | Meaning |
|------|---------|
| 200 | OK |
| 201 | Created |
| 202 | Accepted (async job queued) |
| 204 | No Content |
| 400 | Bad Request |
| 401 | Unauthenticated |
| 403 | Forbidden (not admin) |
| 404 | Not Found |
| 422 | Validation Error |
| 429 | Rate Limited / Quota Exceeded |
| 503 | LLM provider unavailable |

---

## License

MIT
