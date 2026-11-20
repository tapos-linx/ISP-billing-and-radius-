<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 6: Bearer Token Authentication & Multi-Tenant Context Resolver
 * Authenticates users, binds them to Laravel Auth guard, and establishes
 * tenant isolation boundary in TenantContext.
 */
class AuthenticateApiToken
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->resolveToken($request);

        if ($token === null || $token === '') {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'Missing or invalid Bearer API token',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user = User::where('api_token', $token)
            ->where('status', 'active')
            ->first();

        if ($user === null) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'Invalid or expired API token',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Set authenticated user
        Auth::setUser($user);

        // Resolve requested tenant override (SuperAdmin only)
        $requestedTenantId = $request->header('X-Tenant-ID');
        $overrideTenantId = null;

        if ($requestedTenantId !== null && $requestedTenantId !== '') {
            $requestedId = (int) $requestedTenantId;
            if ($user->isSuperAdmin()) {
                $overrideTenantId = $requestedId;
            } elseif ($requestedId !== (int) $user->tenant_id) {
                // Horizontal privilege escalation attempt blocked
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'Cross-tenant impersonation is restricted to SuperAdmin',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // Bind request-scoped tenant context
        $this->tenantContext->setFromUser($user, $overrideTenantId);

        return $next($request);
    }

    private function resolveToken(Request $request): ?string
    {
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return trim(substr($authHeader, 7));
        }

        return $request->header('X-Api-Token')
            ?: $request->query('api_token');
    }
}
