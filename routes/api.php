<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\WorkflowController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI Support SaaS — API Routes v1
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api/v1
|
| Middleware stacks:
|   auth:sanctum         — Sanctum token authentication
|   api.rate_limit       — Redis sliding-window RPM limiting
|   api.token_quota      — Daily token quota enforcement
|   admin                — Admin flag or static API key check
|
*/

Route::prefix('v1')->group(function () {

    // =========================================================================
    // Public: Authentication
    // =========================================================================
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login',    [AuthController::class, 'login']);
    });

    // =========================================================================
    // Authenticated Routes
    // =========================================================================
    Route::middleware(['auth:sanctum', 'api.rate_limit'])->group(function () {

        // Auth
        Route::prefix('auth')->group(function () {
            Route::delete('logout', [AuthController::class, 'logout']);
            Route::get('me',        [AuthController::class, 'me']);
        });

        // =====================================================================
        // Tickets
        // =====================================================================
        Route::prefix('tickets')->group(function () {
            Route::get('/',                  [TicketController::class, 'index']);
            Route::post('/',                 [TicketController::class, 'store']);
            Route::get('/{uuid}',            [TicketController::class, 'show']);
            Route::patch('/{uuid}',          [TicketController::class, 'update']);
            Route::delete('/{uuid}',         [TicketController::class, 'destroy']);
            Route::post('/{uuid}/summarize', [TicketController::class, 'summarize']);
            Route::post('/{uuid}/tag',       [TicketController::class, 'tag']);
        });

        // =====================================================================
        // Chat / Messages  (token quota enforced here)
        // =====================================================================
        Route::middleware(['api.token_quota'])->group(function () {
            Route::post('tickets/{uuid}/messages',      [ChatController::class, 'sendMessage']);
            Route::get('tickets/{uuid}/messages',       [ChatController::class, 'listMessages']);
            Route::post('tickets/{uuid}/conversations', [ChatController::class, 'newConversation']);
        });

        // =====================================================================
        // Workflows
        // =====================================================================
        Route::prefix('workflows')->group(function () {
            Route::get('/',              [WorkflowController::class, 'index']);
            Route::post('/',             [WorkflowController::class, 'store']);
            Route::get('/{uuid}',        [WorkflowController::class, 'show']);
            Route::patch('/{uuid}',      [WorkflowController::class, 'update']);
            Route::delete('/{uuid}',     [WorkflowController::class, 'destroy']);
            Route::post('/{uuid}/test',  [WorkflowController::class, 'test']);
            Route::get('/{uuid}/runs',   [WorkflowController::class, 'runs']);
        });

        // =====================================================================
        // Admin (requires is_admin flag or X-Admin-Key header)
        // =====================================================================
        Route::middleware(['admin'])->prefix('admin')->group(function () {
            Route::get('health',                          [AdminController::class, 'health']);
            Route::get('failed-jobs',                     [AdminController::class, 'failedJobs']);
            Route::post('failed-jobs/{uuid}/retry',       [AdminController::class, 'retryFailedJob']);
            Route::get('usage-stats',                     [AdminController::class, 'usageStats']);
            Route::get('users',                           [AdminController::class, 'users']);
            Route::get('queue-stats',                     [AdminController::class, 'queueStats']);
        });
    });
});

// Health ping (no auth, used by load balancer health checks)
Route::get('/ping', fn () => response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]));
