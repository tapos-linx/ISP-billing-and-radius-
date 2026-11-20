<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Rbac\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 6: Multi-Tenant Scoped Invoice & Billing Controller
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly RbacService $rbacService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user && ! $this->rbacService->can($user, 'invoices.view')) {
            return response()->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $invoices = Invoice::query()
            ->orderBy('due_date', 'desc')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json($invoices);
    }

    public function show(string $id): JsonResponse
    {
        $user = Auth::user();
        $invoice = Invoice::where('invoice_id', $id)->first();

        if ($invoice === null) {
            $crossTenant = Invoice::withoutGlobalScopes()->where('invoice_id', $id)->first();
            if ($crossTenant !== null) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'Cross-tenant invoice access strictly prohibited',
                ], Response::HTTP_FORBIDDEN);
            }

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Invoice not found',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($user && ! $this->rbacService->can($user, 'invoices.view', $invoice)) {
            return response()->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'status' => 'success',
            'invoice' => $invoice,
        ]);
    }
}
