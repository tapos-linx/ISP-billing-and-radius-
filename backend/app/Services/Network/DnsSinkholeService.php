<?php

declare(strict_types=1);

namespace App\Services\Network;

use App\Models\DnsSinkholeHit;
use App\Models\DnsSinkholeRule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * P2-3: DNS Sinkholing & BTRC Mandatory Regulatory Content Filtering Service
 */
class DnsSinkholeService
{
    /**
     * Add a regulatory or threat-intelligence sinkhole block rule
     */
    public function addRule(int $tenantId, string $domain, string $category = 'btrc_mandatory', string $sinkholeIp = '10.254.254.254'): DnsSinkholeRule
    {
        $cleanDomain = strtolower(trim($domain));

        /** @var DnsSinkholeRule $rule */
        $rule = DnsSinkholeRule::withoutGlobalScopes()->create([
            'rule_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'domain' => $cleanDomain,
            'category' => $category,
            'sinkhole_ip' => $sinkholeIp,
            'is_active' => true,
            'created_at' => Carbon::now(),
        ]);

        return $rule;
    }

    /**
     * Intercept and evaluate a DNS lookup query against the regulatory sinkhole blocklist
     *
     * @return array<string, mixed>
     */
    public function evaluateDnsQuery(int $tenantId, string $queriedDomain, string $clientIp): array
    {
        $domain = strtolower(trim($queriedDomain));

        /** @var Collection<int, DnsSinkholeRule> $rules */
        $rules = DnsSinkholeRule::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        $matchedRule = null;

        foreach ($rules as $rule) {
            $ruleDomain = (string) $rule->domain;

            // Direct match or subdomain suffix match
            if ($domain === $ruleDomain || str_ends_with($domain, '.'.$ruleDomain)) {
                $matchedRule = $rule;
                break;
            }
        }

        if ($matchedRule !== null) {
            // Log interception hit for regulatory compliance audit
            DnsSinkholeHit::withoutGlobalScopes()->create([
                'rule_id' => $matchedRule->rule_id,
                'tenant_id' => $tenantId,
                'client_ip' => $clientIp,
                'queried_domain' => $domain,
                'blocked_at' => Carbon::now(),
            ]);

            return [
                'blocked' => true,
                'action' => 'sinkhole_intercepted',
                'queried_domain' => $domain,
                'sinkhole_ip' => $matchedRule->sinkhole_ip,
                'category' => $matchedRule->category,
                'rule_id' => $matchedRule->rule_id,
            ];
        }

        return [
            'blocked' => false,
            'action' => 'forward_authoritative',
            'queried_domain' => $domain,
        ];
    }

    /**
     * Export BTRC compliance blocklist interception audit report
     *
     * @return array<string, mixed>
     */
    public function getComplianceSummary(int $tenantId): array
    {
        $activeRulesCount = DnsSinkholeRule::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        $totalHitsCount = DnsSinkholeHit::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        return [
            'regulatory_mandate' => 'BTRC National Security & Content Filtering Guidelines',
            'tenant_id' => $tenantId,
            'active_blocklist_domains' => $activeRulesCount,
            'total_intercepted_queries' => $totalHitsCount,
            'sinkhole_gateway_ip' => '10.254.254.254',
        ];
    }
}
