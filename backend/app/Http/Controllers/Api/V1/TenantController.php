<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 6: Global Tenant Management Controller (SuperAdmin only)
 */
class TenantController extends Controller
{
    public function index(): JsonResponse
    {
        $tenants = Tenant::withCount(['subscribers', 'users', 'ipPools'])->get();

        return response()->json([
            'status' => 'success',
            'tenants' => $tenants,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $tenant = Tenant::with(['ipPools', 'subscribers', 'users'])->find($id);

        if ($tenant === null) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Tenant not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'status' => 'success',
            'tenant' => $tenant,
        ]);
    }
}
