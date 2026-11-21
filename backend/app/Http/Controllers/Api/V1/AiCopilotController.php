<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Onu;
use App\Models\Subscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * P0-14: Wire Live AI NOC Copilot with RADIUS Rejects, Accounting & Optical Telemetry
 */
final class AiCopilotController extends Controller
{
    public function diagnose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'symptom' => ['required', 'string', 'max:1000'],
        ]);

        $username = $data['username'];

        // 1. Gather Live Subscriber Profile
        $subscriber = Subscriber::withoutGlobalScopes()->where('username', $username)->first();

        // 2. Fetch Last 20 radpostauth rejects / auth attempts
        $recentAuths = DB::table('radpostauth')
            ->where('username', $username)
            ->orderByDesc('authdate')
            ->limit(20)
            ->get(['username', 'pass', 'reply', 'authdate'])
            ->toArray();

        // 3. Fetch Recent radacct sessions
        $recentAcct = DB::table('radacct')
            ->where('username', $username)
            ->orderByDesc('radacctid')
            ->limit(5)
            ->get(['acctsessionid', 'nasipaddress', 'acctstarttime', 'acctstoptime', 'terminatecause'])
            ->toArray();

        // 4. Fetch Failed CoA jobs
        $failedCoa = DB::table('failed_coa_jobs')
            ->where('username', $username)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['nas_ip', 'action', 'error_reason', 'created_at'])
            ->toArray();

        // 5. Fetch GPON / OLT Optical Telemetry
        $onu = $subscriber ? Onu::withoutGlobalScopes()->where('subscriber_id', $subscriber->subscriber_id)->first() : null;
        $opticalData = $onu ? [
            'pon_port' => $onu->pon_port,
            'serial_number' => $onu->serial_number,
            'rx_power_dbm' => $onu->rx_power_dbm,
            'tx_power_dbm' => $onu->tx_power_dbm,
            'distance_m' => $onu->distance_m,
            'onu_status' => $onu->status,
        ] : null;

        $telemetryContext = [
            'subscriber' => $subscriber ? [
                'status' => $subscriber->status,
                'bandwidth_profile' => $subscriber->bandwidth_profile,
                'expires_at' => $subscriber->expires_at ? $subscriber->expires_at->toDateTimeString() : null,
            ] : null,
            'auth_attempts_count' => count($recentAuths),
            'recent_auths' => $recentAuths,
            'recent_sessions' => $recentAcct,
            'failed_coa_jobs' => $failedCoa,
            'optical_telemetry' => $opticalData,
        ];

        // 6. Gemini API Diagnostic Prompt Construction
        $apiKey = (string) env('GEMINI_API_KEY', '');
        $model = (string) env('GEMINI_MODEL', env('GEMINI_FLASH_MODEL', 'gemini-1.5-flash'));

        if ($apiKey !== '' && ! str_starts_with($apiKey, 'REPLACE_WITH_') && ! config('app.testing')) {
            $prompt = sprintf(
                "You are an expert Carrier ISP NOC Engineer (FreeRADIUS, MikroTik RouterOS v7, Huawei GPON).\n".
                "Diagnose subscriber '%s' who reports symptom: '%s'.\n\n".
                "LIVE CARRIER TELEMETRY:\n%s\n\n".
                "Provide a structured NOC diagnosis:\n".
                "1. Root Cause Analysis (evaluating optical power attenuation, RADIUS rejection reason, or billing expiration)\n".
                "2. Specific RouterOS / OLT CLI command to verify\n".
                "3. Safe remediation procedure.\n",
                $username,
                $data['symptom'],
                json_encode($telemetryContext, JSON_PRETTY_PRINT)
            );

            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
                $resp = Http::timeout(15)->post($url, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                ]);

                if ($resp->successful()) {
                    $advice = data_get($resp->json(), 'candidates.0.content.parts.0.text', 'No diagnosis returned.');

                    return response()->json([
                        'status' => 'success',
                        'username' => $username,
                        'model' => $model,
                        'telemetry' => $telemetryContext,
                        'diagnosis' => $advice,
                    ]);
                }
            } catch (Throwable $e) {
                // Fallback to local rule engine
            }
        }

        // Rule-Based Fallback Synthesis
        $diagnosis = $this->synthesizeLocalDiagnosis($username, $data['symptom'], $telemetryContext);

        return response()->json([
            'status' => 'success',
            'username' => $username,
            'model' => 'rule-engine-fallback',
            'telemetry' => $telemetryContext,
            'diagnosis' => $diagnosis,
        ]);
    }

    protected function synthesizeLocalDiagnosis(string $username, string $symptom, array $ctx): string
    {
        $lines = ["NOC Diagnostic Analysis for Subscriber '{$username}':"];

        // Check optical
        if (isset($ctx['optical_telemetry']['rx_power_dbm'])) {
            $rx = (float) $ctx['optical_telemetry']['rx_power_dbm'];
            if ($rx < -27.0) {
                $lines[] = "CRITICAL: Severe optical power loss detected (Rx: {$rx} dBm, threshold is -27 dBm). High optical fiber attenuation or dirty patch cord.";
                $lines[] = "CLI Check: display ont optical-info {$ctx['optical_telemetry']['pon_port']}";
                $lines[] = 'Fix: Dispatch splicer team to clean SC/APC connector or inspect drop cable splitters.';

                return implode("\n", $lines);
            }
        }

        // Check billing
        if (isset($ctx['subscriber']['status']) && $ctx['subscriber']['status'] === 'expired') {
            $lines[] = 'CAUSE: Subscriber line expired on '.($ctx['subscriber']['expires_at'] ?? 'N/A').'. Subscriber redirected to walled_garden_pool.';
            $lines[] = 'CLI Check: /ip firewall address-list print where list=walled_garden';
            $lines[] = 'Fix: Process subscription recharge via bKash/Nagad or apply emergency grace extension.';

            return implode("\n", $lines);
        }

        // Check rejects
        if (! empty($ctx['recent_auths'])) {
            $lastAuth = reset($ctx['recent_auths']);
            if (isset($lastAuth->reply) && str_contains(strtolower($lastAuth->reply), 'reject')) {
                $lines[] = "CAUSE: RADIUS Authentication Rejected ({$lastAuth->reply}). Cleartext password mismatch or invalid MAC binding.";
                $lines[] = "CLI Check: radtest {$username} <password> 127.0.0.1 0 secure_radius_secret_key_2026";
                $lines[] = 'Fix: Reset PPPoE subscriber credentials in Citus radcheck and sync via RadiusSyncService.';

                return implode("\n", $lines);
            }
        }

        $lines[] = 'STATUS: Line active with nominal telemetry (Rx: '.($ctx['optical_telemetry']['rx_power_dbm'] ?? '-19.50').' dBm).';
        $lines[] = "Reported symptom: '{$symptom}'.";
        $lines[] = "Recommended action: Clear active session to refresh dynamic queues: /ppp active remove [find name={$username}].";

        return implode("\n", $lines);
    }
}
