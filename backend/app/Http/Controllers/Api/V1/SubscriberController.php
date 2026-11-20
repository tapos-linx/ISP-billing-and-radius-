<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Services\Rbac\RbacService;
use App\Services\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 6: Multi-Tenant Scoped Subscriber Management Controller
 * Strict query isolation prevents cross-tenant and cross-reseller data leakage.
 */
class SubscriberController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RbacService $rbacService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user && ! $this->rbacService->can($user, 'subscribers.view')) {
            return response()->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $subscribers = Subscriber::query()
            ->orderBy('created_at', 'desc')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json($subscribers);
    }

    public function show(string $id): JsonResponse
    {
        $user = Auth::user();

        // 1. Query with TenantScope active
        $subscriber = Subscriber::where('subscriber_id', $id)->first();

        if ($subscriber === null) {
            // Verify if record exists in another tenant to detect cross-tenant breach attempt
            $crossTenant = Subscriber::withoutGlobalScopes()->where('subscriber_id', $id)->first();
            if ($crossTenant !== null) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'Cross-tenant data access strictly prohibited',
                ], Response::HTTP_FORBIDDEN);
            }

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Subscriber not found',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($user && ! $this->rbacService->can($user, 'subscribers.view', $subscriber)) {
            return response()->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'status' => 'success',
            'subscriber' => $subscriber,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user && ! $this->rbacService->can($user, 'subscribers.create')) {
            return response()->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'min:6'],
            'bandwidth_profile' => ['nullable', 'string', 'max:64'],
            'mac_address' => ['nullable', 'string', 'max:17'],
            'vlan_id' => ['nullable', 'integer'],
        ]);

        // Auto-assign tenant ID from active context / authenticated user
        $tenantId = $this->tenantContext->getTenantId() ?? ($user ? (int) $user->tenant_id : 1);
        $subId = (string) Str::uuid();

        $subscriber = DB::transaction(function () use ($subId, $tenantId, $data) {
            $sub = Subscriber::create([
                'subscriber_id' => $subId,
                'tenant_id' => $tenantId,
                'username' => $data['username'],
                'bandwidth_profile' => $data['bandwidth_profile'] ?? '20M/20M',
                'mac_address' => $data['mac_address'] ?? null,
                'vlan_id' => $data['vlan_id'] ?? null,
                'status' => 'active',
                'expires_at' => now()->addDays(30),
            ]);

            // Add radcheck credential
            DB::table('radcheck')->insert([
                'subscriber_id' => $subId,
                'username' => $data['username'],
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $data['password'],
            ]);

            return $sub;
        });

        return response()->json([
            'status' => 'created',
            'subscriber' => $subscriber,
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = Auth::user();
        $subscriber = Subscriber::where('subscriber_id', $id)->first();

        if ($subscriber === null) {
            return response()->json(['error' => 'Subscriber not found'], Response::HTTP_NOT_FOUND);
        }

        if ($user && ! $this->rbacService->can($user, 'subscribers.update', $subscriber)) {
            return response()->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'bandwidth_profile' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:active,suspended,expired'],
        ]);

        $subscriber->update(array_filter($data));

        return response()->json([
            'status' => 'updated',
            'subscriber' => $subscriber,
        ]);
    }
}
