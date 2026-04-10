<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts access to admin-only routes.
 * Supports both user-level admin flag and a static API key for internal tools.
 */
class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Allow static admin API key (for internal dashboards / monitoring tools)
        $adminApiKey = config('app.admin_api_key');
        if ($adminApiKey && $request->header('X-Admin-Key') === $adminApiKey) {
            return $next($request);
        }

        $user = $request->user();

        if (!$user || !$user->is_admin) {
            return response()->json(['error' => 'Unauthorized. Admin access required.'], 403);
        }

        return $next($request);
    }
}
