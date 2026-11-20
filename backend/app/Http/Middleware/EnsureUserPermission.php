<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Rbac\RbacService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 6: RBAC Granular Permission Verification Middleware
 */
class EnsureUserPermission
{
    public function __construct(
        private readonly RbacService $rbacService
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'Authentication required',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $this->rbacService->can($user, $permission)) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Access denied: permission not granted',
                'required_permission' => $permission,
                'user_role' => $user->role,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
