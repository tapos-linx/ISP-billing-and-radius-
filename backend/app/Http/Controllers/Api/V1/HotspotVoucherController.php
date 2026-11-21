<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HotspotProfile;
use App\Models\HotspotVoucher;
use App\Services\Hotspot\HotspotVoucherService;
use App\Services\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Release 2.0 (Epic 1): Wi-Fi Hotspot Voucher API Controller
 */
class HotspotVoucherController extends Controller
{
    public function __construct(
        protected HotspotVoucherService $voucherService,
        protected TenantContext $tenantContext
    ) {}

    public function indexProfiles(): JsonResponse
    {
        $profiles = HotspotProfile::query()->get();

        return response()->json([
            'status' => 'success',
            'data' => $profiles,
        ]);
    }

    public function createProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:64',
            'validity_seconds' => 'required|integer|min:60',
            'data_limit_bytes' => 'required|integer|min:0',
            'rate_limit_down_kbps' => 'required|integer|min:512',
            'rate_limit_up_kbps' => 'required|integer|min:512',
            'price_bdt' => 'required|numeric|min:0',
            'simultaneous_use' => 'nullable|integer|min:1',
        ]);

        $tenantId = $this->tenantContext->getTenantId() ?? 1;

        $profile = HotspotProfile::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'validity_seconds' => $validated['validity_seconds'],
            'data_limit_bytes' => $validated['data_limit_bytes'],
            'rate_limit_down_kbps' => $validated['rate_limit_down_kbps'],
            'rate_limit_up_kbps' => $validated['rate_limit_up_kbps'],
            'price_bdt' => $validated['price_bdt'],
            'simultaneous_use' => $validated['simultaneous_use'] ?? 1,
            'created_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Hotspot profile created successfully.',
            'data' => $profile,
        ], 201);
    }

    public function generateBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch_id' => 'required|string|max:32',
            'profile_id' => 'required|integer|exists:hotspot_profiles,profile_id',
            'count' => 'required|integer|min:1|max:1000',
            'custom_price' => 'nullable|numeric|min:0',
        ]);

        $tenantId = $this->tenantContext->getTenantId() ?? 1;

        $vouchers = $this->voucherService->generateBatch(
            $tenantId,
            $validated['batch_id'],
            $validated['count'],
            $validated['profile_id'],
            isset($validated['custom_price']) ? (float) $validated['custom_price'] : null
        );

        return response()->json([
            'status' => 'success',
            'message' => sprintf('Successfully generated batch %s with %d vouchers.', $validated['batch_id'], count($vouchers)),
            'batch_id' => $validated['batch_id'],
            'count' => count($vouchers),
            'vouchers' => $vouchers,
        ], 201);
    }

    public function getVoucher(string $code): JsonResponse
    {
        $voucher = HotspotVoucher::withoutGlobalScopes()->where('code', $code)->first();

        if (! $voucher) {
            return response()->json([
                'status' => 'error',
                'message' => 'Voucher not found.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $voucher,
            'is_usable' => $voucher->isUsable(),
            'wispr_attributes' => $voucher->getWisprAttributes(),
        ]);
    }

    public function renderReceipt(string $code): Response
    {
        $voucher = HotspotVoucher::withoutGlobalScopes()->where('code', $code)->firstOrFail();
        $receiptText = $this->voucherService->renderEscPosReceipt($voucher);

        return response($receiptText, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "inline; filename=\"receipt_{$code}.txt\"",
        ]);
    }

    public function authVerify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:32',
            'mac_address' => 'nullable|string|max:17',
        ]);

        $result = $this->voucherService->verifyAndAuthorize(
            $validated['code'],
            $validated['mac_address'] ?? null
        );

        $httpStatus = $result['authorized'] ? 200 : 401;

        return response()->json($result, $httpStatus);
    }

    public function consumeQuota(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate([
            'added_seconds' => 'required|integer|min:0',
            'added_bytes' => 'required|integer|min:0',
        ]);

        $result = $this->voucherService->consumeQuota(
            $code,
            $validated['added_seconds'],
            $validated['added_bytes']
        );

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }
}
