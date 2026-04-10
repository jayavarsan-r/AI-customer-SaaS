<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'unique:users,email'],
            'password'     => ['required', 'string', 'min:8', 'confirmed'],
            'company_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::create([
            'name'                 => $validated['name'],
            'email'                => $validated['email'],
            'password'             => $validated['password'],
            'company_name'         => $validated['company_name'] ?? null,
            'plan'                 => 'free',
            'monthly_token_quota'  => 100_000,
            'daily_token_quota'    => 10_000,
        ]);

        $token = $user->createToken('api-token', ['*'], now()->addDays(30));

        return response()->json([
            'user'  => $this->userResource($user),
            'token' => $token->plainTextToken,
        ], 201);
    }

    /**
     * POST /api/v1/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->where('is_active', true)->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Revoke all previous tokens on login (single session per user)
        $user->tokens()->delete();

        $token = $user->createToken('api-token', ['*'], now()->addDays(30));

        return response()->json([
            'user'  => $this->userResource($user),
            'token' => $token->plainTextToken,
        ]);
    }

    /**
     * DELETE /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('usageDailySummaries');

        return response()->json([
            'user'        => $this->userResource($user),
            'plan_limits' => $user->getPlanLimits(),
            'usage_today' => [
                'tokens_used'      => $user->usageLogs()->whereDate('usage_date', today())->sum('total_tokens'),
                'tokens_remaining' => $user->getRemainingDailyTokens(),
            ],
        ]);
    }

    private function userResource(User $user): array
    {
        return [
            'id'           => $user->uuid,
            'name'         => $user->name,
            'email'        => $user->email,
            'company_name' => $user->company_name,
            'plan'         => $user->plan,
            'is_admin'     => $user->is_admin,
            'created_at'   => $user->created_at->toIso8601String(),
        ];
    }
}
