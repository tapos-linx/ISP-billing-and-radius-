<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\BillingAuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P2-11: Granular Audit Log Tamper-Evident Verification Service
 */
class AuditChainVerificationService
{
    /**
     * Perform deep forensic verification of the audit log backward hash-chain.
     *
     * @return array{
     *     total_logs_examined: int,
     *     is_valid: bool,
     *     first_event_id: ?string,
     *     last_event_id: ?string,
     *     tampered_rows: array<int, array{id: int, event_id: string, reason: string, expected_prev: string, actual_prev_hash: string}>,
     *     sequence_breaks: array<int, array{id: int, reason: string}>,
     *     chain_root_hash: string,
     *     verified_at: string
     * }
     */
    public function verifyChain(?string $startDate = null, ?string $endDate = null): array
    {
        $query = DB::table('billing_audit_logs')->orderBy('id', 'asc');

        if ($startDate) {
            $query->where('created_at', '>=', Carbon::parse($startDate)->startOfDay());
        }
        if ($endDate) {
            $query->where('created_at', '<=', Carbon::parse($endDate)->endOfDay());
        }

        $logs = $query->get();

        $tamperedRows = [];
        $sequenceBreaks = [];
        $expectedPrevHash = str_repeat('0', 64);
        $cumulativeHash = str_repeat('0', 64);
        $lastTimestamp = null;

        /** @var string|null $firstEventId */
        $firstEventId = null;
        /** @var string|null $lastEventId */
        $lastEventId = null;

        foreach ($logs as $index => $log) {
            if ($index === 0) {
                $firstEventId = (string) $log->event_id;
                // If query is filtered by date, expectedPrevHash starts at the first log's prev_hash
                if ($startDate) {
                    $expectedPrevHash = (string) $log->prev_hash;
                }
            }
            $lastEventId = (string) $log->event_id;

            // 1. Verify backward hash pointer
            if ($log->prev_hash !== $expectedPrevHash) {
                $tamperedRows[] = [
                    'id' => (int) $log->id,
                    'event_id' => (string) $log->event_id,
                    'reason' => 'Hash chain pointer mismatch; row may have been inserted or preceding row deleted',
                    'expected_prev' => $expectedPrevHash,
                    'actual_prev_hash' => (string) $log->prev_hash,
                ];
            }

            // 2. Verify signature format (64 hex characters)
            if (! preg_match('/^[a-f0-9]{64}$/i', (string) $log->hash_signature)) {
                $tamperedRows[] = [
                    'id' => (int) $log->id,
                    'event_id' => (string) $log->event_id,
                    'reason' => 'Invalid cryptographic hash format',
                    'expected_prev' => $expectedPrevHash,
                    'actual_prev_hash' => (string) $log->prev_hash,
                ];
            }

            // 3. Verify monotonic time sequence
            $currentTimestamp = Carbon::parse($log->created_at)->timestamp;
            if ($lastTimestamp !== null && $currentTimestamp < $lastTimestamp - 5) {
                // Allow up to 5s clock jitter between concurrent workers
                $sequenceBreaks[] = [
                    'id' => (int) $log->id,
                    'reason' => "Timestamp non-monotonic: {$log->created_at} is earlier than previous row",
                ];
            }
            $lastTimestamp = $currentTimestamp;

            // Roll cumulative chain hash
            $cumulativeHash = hash('sha256', $cumulativeHash.$log->hash_signature);
            $expectedPrevHash = (string) $log->hash_signature;
        }

        $isValid = empty($tamperedRows) && empty($sequenceBreaks);

        return [
            'total_logs_examined' => $logs->count(),
            'is_valid' => $isValid,
            'first_event_id' => $firstEventId,
            'last_event_id' => $lastEventId,
            'tampered_rows' => $tamperedRows,
            'sequence_breaks' => $sequenceBreaks,
            'chain_root_hash' => $cumulativeHash,
            'verified_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Test / audit simulation helper to append a forged audit log with a broken chain pointer.
     */
    public function insertForgedRecord(string $brokenPrevHash, string $forgedHash): BillingAuditLog
    {
        return BillingAuditLog::create([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'forged.audit_entry',
            'entity_type' => 'invoice',
            'entity_id' => 'FORGED-ENTITY',
            'actor_type' => 'attacker',
            'actor_id' => 'malicious_actor',
            'prev_state' => null,
            'new_state' => ['forged' => true],
            'ip_address' => '10.0.0.1',
            'hash_signature' => $forgedHash,
            'prev_hash' => $brokenPrevHash,
            'created_at' => now(),
        ]);
    }
}
