<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\FreeSwitchEslListenerCommand;
use App\Models\DialPlanRate;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\VoipCdr;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Release 2.0 (Epic 2): Real-Time SIP Signaling Bridge Feature Test
 */
class SipSignalingBridgeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_sip_invite_authorization_and_redis_credit_reservation(): void
    {
        $tariff = Tariff::withoutGlobalScopes()->first() ?? Tariff::create([
            'name' => 'VoIP Voice Bundle',
            'download_speed' => 10,
            'upload_speed' => 10,
            'price_bdt' => 500.00,
        ]);

        /** @var Subscriber $sub */
        $sub = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'username' => 'sip_user_'.Str::lower(Str::random(5)),
            'password' => 'SipSecret#123',
            'tariff_id' => $tariff->tariff_id,
            'status' => 'active',
            'expiry_date' => Carbon::now()->addMonth(),
            'auto_pay_enabled' => false,
            'pro_rata_balance' => 120.00, // BDT 120 prepaid credit
        ]);

        // Create DialPlanRate: Bangladesh Mobile (+88018) @ BDT 1.80/min, 6-second pulse
        DialPlanRate::withoutGlobalScopes()->create([
            'rate_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'destination_name' => 'Bangladesh Mobile Robi',
            'destination_prefix' => '+88018',
            'rate_per_minute_bdt' => 1.80,
            'pulse_seconds' => 6,
            'is_active' => true,
        ]);

        $callId = 'CALL-INVITE-'.strtoupper(Str::random(8)).'@carrier.sip';

        // 1. Send SIP INVITE Authorization Request
        $inviteResp = $this->postJson('/api/v1/sip/authorize-invite', [
            'tenant_id' => 1,
            'call_id' => $callId,
            'caller_e164' => '+8801700000001',
            'callee_e164' => '+8801812345678',
            'subscriber_id' => (string) $sub->subscriber_id,
        ]);

        $inviteResp->assertStatus(200);
        $this->assertTrue($inviteResp->json('authorized'));
        $this->assertEquals(200, $inviteResp->json('sip_code'));
        $this->assertEquals(1.80, (float) $inviteResp->json('rate_per_minute_bdt'));
        $this->assertEquals(6, $inviteResp->json('pulse_seconds'));
        $this->assertGreaterThan(0, $inviteResp->json('max_duration_seconds'));

        // 2. Verify Atomic Redis Reservation Created
        $reserveKey = "sip:reserve:{$callId}";
        $this->assertNotEmpty(Redis::get($reserveKey));

        // 3. Process SIP BYE Call Hangup
        $started = Carbon::now()->subSeconds(65);
        $answered = Carbon::now()->subSeconds(60);
        $ended = Carbon::now();

        $byeResp = $this->postJson('/api/v1/sip/process-bye', [
            'tenant_id' => 1,
            'call_id' => $callId,
            'caller_e164' => '+8801700000001',
            'callee_e164' => '+8801812345678',
            'started_at' => $started->toDateTimeString(),
            'answered_at' => $answered->toDateTimeString(),
            'ended_at' => $ended->toDateTimeString(),
            'disposition' => 'ANSWERED',
        ]);

        $byeResp->assertStatus(200);
        $this->assertEquals('settled', $byeResp->json('summary.status'));
        $this->assertEquals(65, $byeResp->json('summary.duration_seconds'));
        $this->assertEquals(60, $byeResp->json('summary.billable_seconds'));
        $this->assertEquals(1.80, (float) $byeResp->json('summary.cost_bdt'));

        // 4. Verify Redis Reservation Key Was Released & Deleted
        $this->assertNull(Redis::get($reserveKey));

        // 5. Verify CDR Stored in DB
        $cdr = VoipCdr::withoutGlobalScopes()->where('call_id', $callId)->first();
        $this->assertNotNull($cdr);
        $this->assertEquals(1.80, (float) $cdr->cost_bdt);
        $this->assertEquals('ANSWERED', $cdr->disposition);
    }

    public function test_sip_insufficient_credit_invite_rejection(): void
    {
        $tariff = Tariff::withoutGlobalScopes()->first() ?? Tariff::create([
            'name' => 'Basic VoIP',
            'download_speed' => 10,
            'upload_speed' => 10,
            'price_bdt' => 500.00,
        ]);

        /** @var Subscriber $sub */
        $sub = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'username' => 'broke_user_'.Str::lower(Str::random(5)),
            'password' => 'Pass#123',
            'tariff_id' => $tariff->tariff_id,
            'status' => 'active',
            'expiry_date' => Carbon::now()->addMonth(),
            'auto_pay_enabled' => false,
            'pro_rata_balance' => 0.00, // 0 balance
        ]);

        $callId = 'CALL-FAIL-'.strtoupper(Str::random(8)).'@carrier.sip';

        $inviteResp = $this->postJson('/api/v1/sip/authorize-invite', [
            'tenant_id' => 1,
            'call_id' => $callId,
            'caller_e164' => '+8801700000002',
            'callee_e164' => '+8801899999999',
            'subscriber_id' => (string) $sub->subscriber_id,
        ]);

        $inviteResp->assertStatus(402);
        $this->assertFalse($inviteResp->json('authorized'));
        $this->assertEquals(402, $inviteResp->json('sip_code'));
        $this->assertStringContainsString('Insufficient balance', $inviteResp->json('reason'));
    }

    public function test_freeswitch_esl_command_simulation(): void
    {
        $command = app(FreeSwitchEslListenerCommand::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput(['--simulate-events' => 2]);
        $output = new BufferedOutput;
        $exitCode = $command->run($input, $output);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('CHANNEL_CREATE', $output->fetch());
    }
}
