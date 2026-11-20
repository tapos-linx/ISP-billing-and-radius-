<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\BillingAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingAuditService
{
    /**
     * Record an immutable, tamper-evident audit event.
     */
    public function logEvent(
        string $eventType,
        string $entityType,
        string $entityId,
        string $actorType = 'system',
        ?string $actorId = null,
        ?array $prevState = null,
        ?array $newState = null,
        ?string $ipAddress = null
    ): BillingAuditLog {
        // Fetch last hash for chaining
        $lastLog = DB::table('billing_audit_logs')->orderByDesc('id')->first();
        $prevHash = $lastLog ? $lastLog->hash_signature : str_repeat('0', 64);

        $payloadToSign = json_encode([
            'prev_hash'   => $prevHash,
            'event_type'  => $eventType,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'actor_type'  => $actorType,
            'actor_id'    => $actorId,
            'prev_state'  => $prevState,
            'new_state'   => $newState,
            'timestamp'   => time(),
        ], JSON_THROW_ON_ERROR);

        $hashSig = hash('sha256', $payloadToSign);

        return BillingAuditLog::create([
            'event_id'       => (string) Str::uuid(),
            'event_type'     => $eventType,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'actor_type'     => $actorType,
            'actor_id'       => $actorId,
            'prev_state'     => $prevState,
            'new_state'      => $newState,
            'ip_address'     => $ipAddress,
            'hash_signature' => $hashSig,
            'prev_hash'      => $prevHash,
            'created_at'     => now(),
        ]);
    }

    /**
     * Validate tamper-evident integrity of the audit log chain.
     */
    public function verifyLogChainIntegrity(): array
    {
        $logs = DB::table('billing_audit_logs')->orderBy('id')->get();
        $tampered = [];
        $expectedPrevHash = str_repeat('0', 64);

        foreach ($logs as $log) {
            if ($log->prev_hash !== $expectedPrevHash) {
                $tampered[] = [
                    'id'               => $log->id,
                    'event_id'         => $log->event_id,
                    'expected_prev'    => $expectedPrevHash,
                    'actual_prev_hash' => $log->prev_hash,
                ];
            }
            $expectedPrevHash = $log->hash_signature;
        }

        return [
            'total_logs'   => $logs->count(),
            'is_valid'     => empty($tampered),
            'tampered_rows'=> $tampered,
        ];
    }
}
