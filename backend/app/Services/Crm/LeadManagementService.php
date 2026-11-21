<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\FeasibilityCheck;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\Subscriber;
use App\Models\Tariff;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * P1-11: Lead Management, Sales Pipeline & Fiber Feasibility Service
 */
class LeadManagementService
{
    /**
     * Ingest a new prospect inquiry into the sales pipeline
     *
     * @param  array<string, mixed>  $data
     */
    public function createLead(int $tenantId, array $data): Lead
    {
        $leadId = (string) ($data['lead_id'] ?? Str::uuid());

        /** @var Lead $lead */
        $lead = Lead::withoutGlobalScopes()->create([
            'lead_id' => $leadId,
            'tenant_id' => $tenantId,
            'name' => (string) $data['name'],
            'phone' => (string) $data['phone'],
            'email' => isset($data['email']) ? (string) $data['email'] : null,
            'address' => (string) $data['address'],
            'lat' => isset($data['lat']) ? (float) $data['lat'] : null,
            'lng' => isset($data['lng']) ? (float) $data['lng'] : null,
            'status' => 'new',
            'stage' => 'inquiry',
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            'created_at' => Carbon::now(),
        ]);

        return $lead;
    }

    /**
     * Perform GIS fiber route feasibility check for prospect location
     */
    public function checkFeasibility(string $leadId, float $lat, float $lng): FeasibilityCheck
    {
        /** @var Lead $lead */
        $lead = Lead::withoutGlobalScopes()->findOrFail($leadId);

        // Approximate distance calculation to nearest POP / Fiber Access Terminal (FAT)
        // Baseline POP reference coordinates (Central Hub: 23.7925, 90.4078)
        $popLat = 23.7925;
        $popLng = 90.4078;
        $deltaLat = ($lat - $popLat) * 111000;
        $deltaLng = ($lng - $popLng) * 111000 * cos(deg2rad($lat));
        $distance = (int) round(sqrt(($deltaLat * $deltaLat) + ($deltaLng * $deltaLng)));

        // Feasible if within 1500 meters of existing fiber distribution closure
        $isFeasible = $distance <= 1500;
        $maxBandwidth = $isFeasible ? ($distance <= 500 ? 500 : 100) : 0;
        $dropFiberMeters = $isFeasible ? max(30, min($distance, 300)) : 0;

        /** @var FeasibilityCheck $check */
        $check = FeasibilityCheck::withoutGlobalScopes()->create([
            'feasibility_id' => (string) Str::uuid(),
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->lead_id,
            'nearest_pop_or_fat' => 'FAT-BANANI-SEC4-C08',
            'distance_meters' => $distance,
            'is_feasible' => $isFeasible,
            'max_bandwidth_mbps' => $maxBandwidth,
            'estimated_drop_fiber_meters' => $dropFiberMeters,
            'checked_at' => Carbon::now(),
        ]);

        $lead->status = 'feasibility_checked';
        $lead->lat = $lat;
        $lead->lng = $lng;
        $lead->save();

        return $check;
    }

    /**
     * Generate an official commercial pricing quote
     */
    public function generateQuote(string $leadId, ?int $tariffId = null, float $installCost = 1500.0, ?float $monthlyRate = null): Quote
    {
        /** @var Lead $lead */
        $lead = Lead::withoutGlobalScopes()->findOrFail($leadId);

        if ($monthlyRate === null && $tariffId !== null) {
            /** @var Tariff|null $tariff */
            $tariff = Tariff::withoutGlobalScopes()->find($tariffId);
            $monthlyRate = $tariff ? (float) ($tariff->price_bdt ?? 1000.0) : 1000.0;
        }

        /** @var Quote $quote */
        $quote = Quote::withoutGlobalScopes()->create([
            'quote_id' => (string) Str::uuid(),
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->lead_id,
            'tariff_id' => $tariffId,
            'installation_charge_bdt' => $installCost,
            'monthly_recurring_bdt' => $monthlyRate ?? 1000.0,
            'status' => 'sent',
            'created_at' => Carbon::now(),
        ]);

        $lead->status = 'quoted';
        $lead->stage = 'proposal';
        $lead->save();

        return $quote;
    }

    /**
     * Convert lead to active subscriber upon contract acceptance
     */
    public function convertLeadToSubscriber(string $leadId, string $username, string $password, ?int $tariffId = null): Subscriber
    {
        /** @var Lead $lead */
        $lead = Lead::withoutGlobalScopes()->findOrFail($leadId);

        $resolvedTariffId = $tariffId;
        if ($resolvedTariffId === null) {
            /** @var Quote|null $quote */
            $quote = Quote::withoutGlobalScopes()->where('lead_id', $leadId)->latest('created_at')->first();
            $resolvedTariffId = $quote && $quote->tariff_id ? (int) $quote->tariff_id : 1;
        }

        /** @var Subscriber $subscriber */
        $subscriber = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => (string) Str::uuid(),
            'tenant_id' => $lead->tenant_id,
            'username' => $username,
            'password' => $password,
            'tariff_id' => $resolvedTariffId,
            'status' => 'active',
            'expiry_date' => Carbon::now()->addDays(30),
            'auto_pay_enabled' => false,
            'pro_rata_balance' => 0.0,
        ]);

        $lead->status = 'converted';
        $lead->stage = 'won';
        $lead->save();

        return $subscriber;
    }
}
