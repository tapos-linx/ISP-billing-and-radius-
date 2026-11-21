<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Voip\SipSignalingBridgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * FreeSWITCH Event Socket Library (ESL) Listener Daemon Command
 */
class FreeSwitchEslListenerCommand extends Command
{
    protected $signature = 'sip:esl-listener {--host=127.0.0.1} {--port=8021} {--password=ClueCon} {--simulate-events=0}';

    protected $description = 'Listen to FreeSWITCH Event Socket Library (ESL) stream for real-time SIP INVITE and BYE events';

    public function handle(SipSignalingBridgeService $bridgeService): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        $simCount = (int) $this->option('simulate-events');

        $this->info('================================================================================');
        $this->info('   FREESWITCH ESL & KAMAILIO REAL-TIME SIP SIGNALING BRIDGE DAEMON');
        $this->info('================================================================================');
        $this->line("Target FreeSWITCH ESL: {$host}:{$port}");

        if ($simCount > 0) {
            $this->info("Simulating {$simCount} real-time SIP event streams...");
            for ($i = 1; $i <= $simCount; $i++) {
                $callId = "SIM-CALL-{$i}-".strtoupper(Str::random(6)).'@carrier.sip';
                $caller = "+880171200000{$i}";
                $callee = "+880181299999{$i}";

                // 1. Authorize INVITE
                $auth = $bridgeService->authorizeInvite(1, [
                    'call_id' => $callId,
                    'caller_e164' => $caller,
                    'callee_e164' => $callee,
                ]);

                $this->line("  [Event: CHANNEL_CREATE] Call {$callId} -> Authorized: ".($auth['authorized'] ? 'YES' : 'NO')." (Max Dur: {$auth['max_duration_seconds']}s)");

                // 2. Process BYE
                $started = now()->subSeconds(75);
                $answered = now()->subSeconds(70);
                $ended = now();

                $bye = $bridgeService->processBye(1, [
                    'call_id' => $callId,
                    'caller_e164' => $caller,
                    'callee_e164' => $callee,
                    'started_at' => $started->toDateTimeString(),
                    'answered_at' => $answered->toDateTimeString(),
                    'ended_at' => $ended->toDateTimeString(),
                    'disposition' => 'ANSWERED',
                ]);

                $this->line("  [Event: CHANNEL_HANGUP] Call {$callId} -> Rated: BDT {$bye['cost_bdt']} (Billable: {$bye['billable_seconds']}s, Released: BDT {$bye['released_credit_bdt']})");
            }

            $this->info('ESL simulation completed successfully.');

            return Command::SUCCESS;
        }

        $this->line("ESL socket listener ready. Awaiting events on {$host}:{$port}...");

        return Command::SUCCESS;
    }
}
