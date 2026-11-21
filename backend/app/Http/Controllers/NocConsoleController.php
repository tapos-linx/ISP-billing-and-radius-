<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BeftnBatch;
use App\Models\DrFailoverDrill;
use App\Models\Invoice;
use App\Models\NetworkDevice;
use App\Models\Olt;
use App\Models\Onu;
use App\Models\OtdrTrace;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\Ticket;
use App\Models\VoipCdr;
use App\Services\Radius\AnycastRadiusTopologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * P0-1: Unified NOC & Admin Console Controller
 */
class NocConsoleController extends Controller
{
    public function dashboard(Request $request): Response|JsonResponse
    {
        $stats = [
            'total_subscribers' => Subscriber::withoutGlobalScopes()->count(),
            'active_subscribers' => Subscriber::withoutGlobalScopes()->where('status', 'active')->count(),
            'expired_subscribers' => Subscriber::withoutGlobalScopes()->where('status', 'expired')->count(),
            'unpaid_invoices_bdt' => (float) Invoice::withoutGlobalScopes()->where('status', 'unpaid')->sum('total_bdt'),
            'open_tickets' => Ticket::withoutGlobalScopes()->whereIn('status', ['open', 'in_progress'])->count(),
            'online_olts' => Olt::withoutGlobalScopes()->where('status', 'online')->count(),
            'provisioned_onus' => Onu::withoutGlobalScopes()->where('status', 'provisioned')->count(),
            'network_devices' => NetworkDevice::withoutGlobalScopes()->count(),
            'failed_coa_count' => DB::table('failed_coa_jobs')->count(),
            'hotspot_vouchers_count' => DB::table('hotspot_vouchers')->count(),
        ];

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'stats' => $stats,
            ]);
        }

        return Inertia::render('Noc/Dashboard', [
            'stats' => $stats,
            'recentSubscribers' => Subscriber::withoutGlobalScopes()->latest('created_at')->limit(10)->get(),
            'recentTickets' => Ticket::withoutGlobalScopes()->latest('created_at')->limit(10)->get(),
        ]);
    }

    public function resources(Request $request, string $resource): JsonResponse
    {
        $data = match ($resource) {
            'subscribers' => Subscriber::withoutGlobalScopes()->paginate(25),
            'nas' => DB::table('nas')->get(),
            'tariffs' => Tariff::withoutGlobalScopes()->get(),
            'invoices' => Invoice::withoutGlobalScopes()->latest('created_at')->paginate(25),
            'ledger' => DB::table('ledger_entries')->latest('posted_at')->paginate(25),
            'failed-coa' => DB::table('failed_coa_jobs')->latest('created_at')->paginate(25),
            'tickets' => Ticket::withoutGlobalScopes()->latest('created_at')->paginate(25),
            'olts' => Olt::withoutGlobalScopes()->with('onus')->get(),
            'devices' => NetworkDevice::withoutGlobalScopes()->with('metrics')->get(),
            default => null,
        };

        if ($data === null) {
            return response()->json(['error' => 'Resource not found'], 404);
        }

        return response()->json([
            'status' => 'success',
            'resource' => $resource,
            'data' => $data,
        ]);
    }

    /**
     * Carrier Operations & Multi-Region Telemetry View
     */
    public function carrierOperations(
        Request $request,
        AnycastRadiusTopologyService $anycastService
    ): Response|JsonResponse {
        $anycastTopology = $anycastService->getTopologyStatus();
        $recentBatches = BeftnBatch::withoutGlobalScopes()->latest()->limit(5)->get();
        $recentCdrs = VoipCdr::withoutGlobalScopes()->latest()->limit(10)->get();
        $recentTraces = OtdrTrace::withoutGlobalScopes()->orderByDesc('traced_at')->limit(5)->get();
        $recentDrills = DrFailoverDrill::withoutGlobalScopes()->latest()->limit(5)->get();

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'anycast_topology' => $anycastTopology,
                'recent_batches' => $recentBatches,
                'recent_cdrs' => $recentCdrs,
                'recent_traces' => $recentTraces,
                'recent_drills' => $recentDrills,
            ]);
        }

        return Inertia::render('Noc/CarrierOperations', [
            'anycastTopology' => $anycastTopology,
            'recentClearingBatches' => $recentBatches,
            'recentSipCdrs' => $recentCdrs,
            'recentOtdrTraces' => $recentTraces,
            'recentDrDrills' => $recentDrills,
        ]);
    }
}
