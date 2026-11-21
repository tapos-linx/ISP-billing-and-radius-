<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Onu;
use App\Models\Subscriber;
use App\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0-1, P0-13, P0-14: Helpdesk Ticketing, AI NOC Copilot & Admin Console Tests
 */
class HelpdeskAndCopilotTest extends TestCase
{
    public function test_helpdesk_ticket_lifecycle_and_sla_timers(): void
    {
        $subId = (string) Str::uuid();

        Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'username' => 'ticket_sub_'.time(),
            'bandwidth_profile' => '20M/20M',
            'status' => 'active',
            'created_at' => Carbon::now(),
        ]);

        // 1. Create Ticket with Critical SLA (60 minutes)
        $createResponse = $this->postJson('/api/v1/helpdesk/tickets', [
            'subject' => 'Complete link down at Banani POP',
            'category' => 'connectivity',
            'priority' => 'critical',
            'subscriber_id' => $subId,
            'initial_comment' => 'Optical LOS red indicator flashing on ONU',
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.priority', 'critical')
            ->assertJsonPath('data.status', 'open');

        $ticketId = $createResponse->json('data.ticket_id');
        $this->assertNotNull($ticketId);

        $ticket = Ticket::withoutGlobalScopes()->where('ticket_id', $ticketId)->first();
        $this->assertNotNull($ticket->sla_due_at);
        $this->assertTrue($ticket->sla_due_at->isFuture());

        // 2. Add Comment
        $commentResponse = $this->postJson("/api/v1/helpdesk/tickets/{$ticketId}/comments", [
            'comment' => 'NOC team is testing the optical core with OTDR.',
            'is_internal' => false,
        ]);

        $commentResponse->assertStatus(201)
            ->assertJsonPath('status', 'success');

        // 3. Resolve Ticket
        $resolveResponse = $this->postJson("/api/v1/helpdesk/tickets/{$ticketId}/resolve", [
            'notes' => 'Fiber patch cable was replaced and Rx power restored to -19 dBm.',
        ]);

        $resolveResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'resolved');

        $ticket->refresh();
        $this->assertEquals('resolved', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
    }

    public function test_helpdesk_canned_replies(): void
    {
        $response = $this->getJson('/api/v1/helpdesk/canned-replies');
        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertGreaterThan(0, count($response->json('data')));
    }

    public function test_live_ai_copilot_with_telemetry_gathering(): void
    {
        $subId = (string) Str::uuid();
        $username = 'ai_test_sub_'.time();

        $subscriber = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'username' => $username,
            'bandwidth_profile' => '20M/20M',
            'status' => 'active',
            'created_at' => Carbon::now(),
        ]);

        // Insert radpostauth reject
        DB::table('radpostauth')->insert([
            'username' => $username,
            'pass' => 'wrong_pass',
            'reply' => 'Access-Reject',
            'authdate' => Carbon::now(),
        ]);

        // Insert OLT ONU with severe optical attenuation (-29 dBm)
        Onu::withoutGlobalScopes()->create([
            'onu_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'olt_id' => (string) Str::uuid(),
            'subscriber_id' => $subId,
            'pon_port' => '0/1/3',
            'serial_number' => 'HWTC'.strtoupper(Str::random(8)),
            'vlan_id' => 110,
            'status' => 'provisioned',
            'rx_power_dbm' => -29.50,
            'tx_power_dbm' => 1.80,
            'distance_m' => 1800,
            'created_at' => Carbon::now(),
        ]);

        // Query AI Copilot
        $response = $this->postJson('/api/v1/ai/diagnose', [
            'username' => $username,
            'symptom' => 'Frequent disconnections and buffering during video calls',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('username', $username);

        $data = $response->json();
        $this->assertArrayHasKey('telemetry', $data);
        $this->assertEquals(-29.50, $data['telemetry']['optical_telemetry']['rx_power_dbm']);
        $this->assertGreaterThanOrEqual(1, $data['telemetry']['auth_attempts_count']);
        $this->assertNotEmpty($data['diagnosis']);
        $this->assertStringContainsString('optical', strtolower($data['diagnosis']));
    }

    public function test_noc_console_dashboard_and_resource_endpoints(): void
    {
        // 1. Dashboard JSON
        $dashResponse = $this->getJson('/noc/dashboard');
        $dashResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'stats' => [
                    'total_subscribers',
                    'active_subscribers',
                    'expired_subscribers',
                    'unpaid_invoices_bdt',
                    'open_tickets',
                    'online_olts',
                    'provisioned_onus',
                    'network_devices',
                    'failed_coa_count',
                    'hotspot_vouchers_count',
                ],
            ]);

        // 2. Resources API
        $subRes = $this->getJson('/noc/resources/subscribers');
        $subRes->assertStatus(200)->assertJsonPath('status', 'success');

        $tktRes = $this->getJson('/noc/resources/tickets');
        $tktRes->assertStatus(200)->assertJsonPath('status', 'success');

        $tarRes = $this->getJson('/noc/resources/tariffs');
        $tarRes->assertStatus(200)->assertJsonPath('status', 'success');
    }
}
