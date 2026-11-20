<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IpPool;
use App\Services\Rbac\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 6: Multi-Tenant Allocated IP Pool Controller
 */
class IpPoolController extends Controller
{
    public function __construct(
        private readonly RbacService $rbacService
    ) {}

    public function index(): JsonResponse
    {
        $user = Auth::user();

        if ($user && ! $this->rbacService->can($user, 'ip_pools.view')) {
            return response()->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $pools = IpPool::query()->get();

        return response()->json([
            'status' => 'success',
            'pools' => $pools,
        ]);
    }
}
