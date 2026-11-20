<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\IpPool;
use App\Models\Subscriber;
use App\Models\Tenant;
use App\Services\Tenant\TenantContext;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 6: Multi-Tenancy, Reseller Data Isolation & RBAC Feature Tests
 * Proves:
 *  1. SuperAdmin has unrestricted multi-tenant visibility.
 *  2. Reseller query scoping strictly isolates subscribers, IP pools, and invoices.
 *  3. Cross-tenant direct resource access is blocked with 403/404.
 *  4. RBAC role enforcement blocks unauthorized role endpoints (e.g. tenants management).
 *  5. Direct Eloquent queries strictly respect TenantScope.
 */
class MultiTenancyAndRbacTest extends TestCase
{
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantContext = app(TenantContext::class);
        $this->tenantContext->reset();

        // Ensure test fixtures exist across tenants 1, 2, and 3
        $this->seedMultiTenantFixtures();
    }

    protected function tearDown(): void
    {
        $this->tenantContext->reset();
        parent::tearDown();
    }

    public function test_superadmin_can_view_all_tenants(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer token_superadmin_carrier_2026')
            ->getJson('/api/v1/tenants');

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'tenants']);
        $this->assertGreaterThanOrEqual(3, count($response->json('tenants')));
    }

    public function test_reseller_is_blocked_from_superadmin_endpoints(): void
    {
        // Reseller Uttara attempts to access /api/v1/tenants
        $response = $this->withHeader('Authorization', 'Bearer token_reseller_uttara_2026')
            ->getJson('/api/v1/tenants');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => 'Forbidden',
        ]);
    }

    public function test_reseller_sees_only_own_tenant_subscribers(): void
    {
        // Reseller Uttara (tenant_id = 2) lists subscribers
        $response = $this->withHeader('Authorization', 'Bearer token_reseller_uttara_2026')
            ->getJson('/api/v1/subscribers');

        $response->assertStatus(200);
        $data = $response->json('data') ?? [];

        $this->assertNotEmpty($data);
        foreach ($data as $sub) {
            $this->assertEquals(2, $sub['tenant_id'], "Cross-tenant leak detected: subscriber from tenant {$sub['tenant_id']} leaked to reseller 2");
        }
    }

    public function test_reseller_sees_only_own_allocated_ip_pools(): void
    {
        // Reseller Uttara lists IP pools
        $response = $this->withHeader('Authorization', 'Bearer token_reseller_uttara_2026')
            ->getJson('/api/v1/ip-pools');

        $response->assertStatus(200);
        $pools = $response->json('pools');

        $this->assertNotEmpty($pools);
        foreach ($pools as $pool) {
            $this->assertEquals(2, $pool['tenant_id'], 'Cross-tenant IP pool leak detected');
            $this->assertEquals('uttara_north_pool', $pool['pool_name']);
        }
    }

    public function test_cross_tenant_subscriber_direct_access_is_blocked(): void
    {
        // Dhanmondi subscriber belongs to tenant 3
        $dhanmondiSub = Subscriber::withoutGlobalScopes()
            ->where('tenant_id', 3)
            ->firstOrFail();

        // Reseller Uttara (tenant 2) tries to fetch Dhanmondi subscriber by UUID
        $response = $this->withHeader('Authorization', 'Bearer token_reseller_uttara_2026')
            ->getJson("/api/v1/subscribers/{$dhanmondiSub->subscriber_id}");

        $this->assertTrue(
            in_array($response->status(), [403, 404], true),
            "Expected 403 or 404 for cross-tenant access attempt, got {$response->status()}"
        );
    }

    public function test_cross_tenant_invoice_direct_access_is_blocked(): void
    {
        // Invoice belongs to tenant 3
        $dhanmondiInv = Invoice::withoutGlobalScopes()
            ->where('tenant_id', 3)
            ->firstOrFail();

        // Reseller Uttara (tenant 2) tries to fetch Dhanmondi invoice by UUID
        $response = $this->withHeader('Authorization', 'Bearer token_reseller_uttara_2026')
            ->getJson("/api/v1/invoices/{$dhanmondiInv->invoice_id}");

        $this->assertTrue(
            in_array($response->status(), [403, 404], true),
            "Expected 403 or 404 for cross-tenant invoice access attempt, got {$response->status()}"
        );
    }

    public function test_reseller_creating_subscriber_is_automatically_pinned_to_their_tenant(): void
    {
        $newUsername = 'uttara_sub_'.Str::random(6);

        $response = $this->withHeader('Authorization', 'Bearer token_reseller_uttara_2026')
            ->postJson('/api/v1/subscribers', [
                'username' => $newUsername,
                'password' => 'SecretPass2026!',
                'bandwidth_profile' => '10M/10M',
            ]);

        $response->assertStatus(201);
        $subId = $response->json('subscriber.subscriber_id');
        $this->assertNotEmpty($subId);

        // Verify in DB that it is strictly pinned to tenant_id = 2
        $created = Subscriber::withoutGlobalScopes()->where('subscriber_id', $subId)->first();
        $this->assertNotNull($created);
        $this->assertEquals(2, $created->tenant_id);
    }

    public function test_billing_clerk_cannot_create_subscriber(): void
    {
        // BillingClerk has permissions for invoices/payments, but NOT subscribers.create
        $response = $this->withHeader('Authorization', 'Bearer token_billing_carrier_2026')
            ->postJson('/api/v1/subscribers', [
                'username' => 'unauthorized_sub_'.Str::random(4),
                'password' => 'Pass123456',
            ]);

        $response->assertStatus(403);
    }

    public function test_eloquent_global_tenant_scope_direct_queries(): void
    {
        // Set tenant context to Tenant 2
        $this->tenantContext->setTenantId(2);
        $this->tenantContext->setSuperAdmin(false);

        $scopedSubs = Subscriber::all();
        $this->assertNotEmpty($scopedSubs);
        foreach ($scopedSubs as $sub) {
            $this->assertEquals(2, $sub->tenant_id);
        }

        $scopedPools = IpPool::all();
        $this->assertNotEmpty($scopedPools);
        foreach ($scopedPools as $pool) {
            $this->assertEquals(2, $pool->tenant_id);
        }

        // Switch to SuperAdmin mode
        $this->tenantContext->setTenantId(null);
        $this->tenantContext->setSuperAdmin(true);

        $allSubs = Subscriber::all();
        $this->assertGreaterThan($scopedSubs->count(), $allSubs->count(), 'SuperAdmin should view records across all tenants');
    }

    private function seedMultiTenantFixtures(): void
    {
        // Seed subscriber in Tenant 2 (Uttara)
        Subscriber::withoutGlobalScopes()->updateOrInsert(
            ['username' => 'uttara_pilot_sub'],
            [
                'subscriber_id' => '22222222-2222-2222-2222-222222222222',
                'tenant_id' => 2,
                'bandwidth_profile' => '15M/15M',
                'status' => 'active',
                'expires_at' => now()->addDays(30),
            ]
        );

        // Seed invoice in Tenant 2
        Invoice::withoutGlobalScopes()->updateOrInsert(
            ['invoice_id' => '22222222-fa00-2222-2222-222222222222'],
            [
                'subscriber_id' => '22222222-2222-2222-2222-222222222222',
                'tenant_id' => 2,
                'amount_bdt' => 800.00,
                'status' => 'paid',
                'due_date' => now()->addDays(5),
            ]
        );

        // Seed subscriber in Tenant 3 (Dhanmondi)
        Subscriber::withoutGlobalScopes()->updateOrInsert(
            ['username' => 'dhanmondi_pilot_sub'],
            [
                'subscriber_id' => '33333333-3333-3333-3333-333333333333',
                'tenant_id' => 3,
                'bandwidth_profile' => '25M/25M',
                'status' => 'active',
                'expires_at' => now()->addDays(30),
            ]
        );

        // Seed invoice in Tenant 3
        Invoice::withoutGlobalScopes()->updateOrInsert(
            ['invoice_id' => '33333333-fa00-3333-3333-333333333333'],
            [
                'subscriber_id' => '33333333-3333-3333-3333-333333333333',
                'tenant_id' => 3,
                'amount_bdt' => 1200.00,
                'status' => 'unpaid',
                'due_date' => now()->addDays(7),
            ]
        );
    }
}
