<?php

declare(strict_types=1);

namespace App\Services\Hotspot;

use App\Models\HotspotProfile;
use App\Models\HotspotVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Release 2.0 (Epic 1): Wi-Fi Hotspot Voucher Generation, POS Rendering & Fast-Path AAA
 */
class HotspotVoucherService
{
    private const CHARSET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // Excludes 0, 1, I, O, L

    /**
     * Generate a batch of Wi-Fi Hotspot Vouchers and pre-warm Redis fast-path
     */
    public function generateBatch(
        int $tenantId,
        string $batchId,
        int $count,
        int $profileId,
        ?float $customPrice = null
    ): array {
        $profile = HotspotProfile::withoutGlobalScopes()->findOrFail($profileId);
        $price = $customPrice !== null ? $customPrice : (float) $profile->price_bdt;

        $vouchers = [];
        $recordsToInsert = [];
        $now = now();

        for ($i = 0; $i < $count; $i++) {
            $code = $this->generateUniqueCode();
            $pin = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $voucherId = (string) Str::uuid();

            $record = [
                'voucher_id' => $voucherId,
                'tenant_id' => $tenantId,
                'profile_id' => $profileId,
                'batch_id' => $batchId,
                'code' => $code,
                'pin' => $pin,
                'status' => 'GENERATED',
                'time_limit_seconds' => $profile->validity_seconds,
                'data_limit_bytes' => $profile->data_limit_bytes,
                'used_time_seconds' => 0,
                'used_bytes' => 0,
                'rate_limit_down_kbps' => $profile->rate_limit_down_kbps,
                'rate_limit_up_kbps' => $profile->rate_limit_up_kbps,
                'price_bdt' => $price,
                'mac_address' => null,
                'first_login_at' => null,
                'expires_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $recordsToInsert[] = $record;
            $vouchers[] = $record;

            // Pre-warm Redis fast-path (<0.4ms)
            $this->cacheVoucherInRedis($code, $record);
        }

        DB::table('hotspot_vouchers')->insert($recordsToInsert);

        return $vouchers;
    }

    /**
     * Verify voucher authentication (Fast-path from Redis or Citus fallback)
     */
    public function verifyAndAuthorize(string $code, ?string $macAddress = null): array
    {
        $cached = $this->getVoucherFromRedis($code);
        $voucher = null;

        if ($cached) {
            $voucherData = $cached;
        } else {
            $voucher = HotspotVoucher::withoutGlobalScopes()->where('code', $code)->first();
            if (! $voucher) {
                return [
                    'authorized' => false,
                    'reason' => 'VOUCHER_NOT_FOUND',
                    'message' => 'Invalid or unknown voucher code.',
                ];
            }
            $voucherData = $voucher->toArray();
            $this->cacheVoucherInRedis($code, $voucherData);
        }

        // Validate status
        if (! in_array($voucherData['status'], ['GENERATED', 'PURCHASED', 'ACTIVE'], true)) {
            return [
                'authorized' => false,
                'reason' => 'VOUCHER_INACTIVE',
                'message' => 'Voucher is '.$voucherData['status'],
            ];
        }

        // Handle first login activation
        if ($voucherData['status'] !== 'ACTIVE') {
            $now = now();
            $expiresAt = $voucherData['time_limit_seconds'] > 0
                ? $now->copy()->addSeconds($voucherData['time_limit_seconds'])
                : null;

            DB::table('hotspot_vouchers')
                ->where('code', $code)
                ->update([
                    'status' => 'ACTIVE',
                    'mac_address' => $macAddress,
                    'first_login_at' => $now,
                    'expires_at' => $expiresAt,
                    'updated_at' => $now,
                ]);

            $voucherData['status'] = 'ACTIVE';
            $voucherData['mac_address'] = $macAddress;
            $voucherData['first_login_at'] = (string) $now;
            $voucherData['expires_at'] = $expiresAt ? (string) $expiresAt : null;
            $this->cacheVoucherInRedis($code, $voucherData);
        }

        $downBps = $voucherData['rate_limit_down_kbps'] * 1024;
        $upBps = $voucherData['rate_limit_up_kbps'] * 1024;
        $rateLimitStr = sprintf('%dM/%dM', intval($voucherData['rate_limit_down_kbps'] / 1024), intval($voucherData['rate_limit_up_kbps'] / 1024));

        $remainingTime = $voucherData['time_limit_seconds'] > 0
            ? max(0, $voucherData['time_limit_seconds'] - $voucherData['used_time_seconds'])
            : 86400;

        return [
            'authorized' => true,
            'code' => $code,
            'status' => 'ACTIVE',
            'mac_address' => $macAddress,
            'attributes' => [
                'WISPr-Bandwidth-Max-Down' => $downBps,
                'WISPr-Bandwidth-Max-Up' => $upBps,
                'Mikrotik-Rate-Limit' => $rateLimitStr,
                'Session-Timeout' => $remainingTime,
                'Idle-Timeout' => 300,
            ],
            'session_limits' => [
                'time_limit_seconds' => $voucherData['time_limit_seconds'],
                'data_limit_bytes' => $voucherData['data_limit_bytes'],
                'used_time_seconds' => $voucherData['used_time_seconds'],
                'used_bytes' => $voucherData['used_bytes'],
            ],
        ];
    }

    /**
     * Consume quota (Time / Bytes) during interim accounting updates
     */
    public function consumeQuota(string $code, int $addedSeconds, int $addedBytes): array
    {
        $voucher = HotspotVoucher::withoutGlobalScopes()->where('code', $code)->firstOrFail();

        $newTime = $voucher->used_time_seconds + $addedSeconds;
        $newBytes = $voucher->used_bytes + $addedBytes;
        $newStatus = $voucher->status;

        if ($voucher->time_limit_seconds > 0 && $newTime >= $voucher->time_limit_seconds) {
            $newStatus = 'DEPLETED';
        } elseif ($voucher->data_limit_bytes > 0 && $newBytes >= $voucher->data_limit_bytes) {
            $newStatus = 'DEPLETED';
        }

        $voucher->used_time_seconds = $newTime;
        $voucher->used_bytes = $newBytes;
        $voucher->status = $newStatus;
        $voucher->save();

        // Update Redis
        $this->cacheVoucherInRedis($code, $voucher->toArray());

        return [
            'code' => $code,
            'status' => $newStatus,
            'used_time_seconds' => $newTime,
            'used_bytes' => $newBytes,
            'depleted' => ($newStatus === 'DEPLETED'),
        ];
    }

    /**
     * Generate 80mm ESC/POS Thermal Receipt for POS Printers
     */
    public function renderEscPosReceipt(HotspotVoucher $voucher): string
    {
        $profile = $voucher->profile ?? HotspotProfile::withoutGlobalScopes()->find($voucher->profile_id);
        $profileName = $profile ? $profile->name : 'Wi-Fi Speed Pass';
        $downM = intval($voucher->rate_limit_down_kbps / 1024);
        $upM = intval($voucher->rate_limit_up_kbps / 1024);
        $dataMb = $voucher->data_limit_bytes > 0 ? intval($voucher->data_limit_bytes / (1024 * 1024)).' MB' : 'Unlimited';
        $timeHours = intval($voucher->time_limit_seconds / 3600);
        $timeStr = $timeHours > 0 ? "{$timeHours} Hour(s)" : ($voucher->time_limit_seconds.' Seconds');

        $price = (float) $voucher->price_bdt;
        $subtotal = round($price / 1.05, 2);
        $vat5 = round($price - $subtotal, 2);

        $line = str_repeat('-', 40);
        $doubleLine = str_repeat('=', 40);

        $out = [];
        $out[] = $doubleLine;
        $out[] = '        TAPOS HIGH-SPEED WI-FI';
        $out[] = '         PUBLIC HOTSPOT PASS';
        $out[] = $doubleLine;
        $out[] = sprintf('Batch: %-15s Date: %s', $voucher->batch_id, now()->format('d-M-Y'));
        $out[] = sprintf('Plan:  %-28s', $profileName);
        $out[] = sprintf('Speed: %dM Down / %dM Up', $downM, $upM);
        $out[] = sprintf('Limit: %-15s Time: %s', $dataMb, $timeStr);
        $out[] = $line;
        $out[] = '  >>> VOUCHER CODE:  '.$voucher->code.' <<<';
        $out[] = '  >>> PIN CODE:       '.($voucher->pin ?? 'N/A').'    <<<';
        $out[] = $line;
        $out[] = sprintf('Subtotal (excl. VAT):     BDT %8.2f', $subtotal);
        $out[] = sprintf('NBR 5%% VAT (Mushak-6.3): BDT %8.2f', $vat5);
        $out[] = sprintf('TOTAL AMOUNT PAID:        BDT %8.2f', $price);
        $out[] = $line;
        $out[] = 'HOW TO CONNECT:';
        $out[] = " 1. Connect to Wi-Fi SSID: 'Tapos-WiFi'";
        $out[] = ' 2. Open browser -> login page appears';
        $out[] = ' 3. Enter Voucher Code and click LOGIN';
        $out[] = 'bKash Pay QR Payload: [bKash:WIFI?v='.$voucher->code.']';
        $out[] = $doubleLine;
        $out[] = '       THANK YOU FOR CHOOSING US!';
        $out[] = "\x1b\x64\x02"; // ESC/POS cut feed bytes

        return implode("\n", $out);
    }

    private function generateUniqueCode(): string
    {
        $len = strlen(self::CHARSET);
        $p1 = '';
        $p2 = '';

        for ($i = 0; $i < 4; $i++) {
            $p1 .= self::CHARSET[random_int(0, $len - 1)];
            $p2 .= self::CHARSET[random_int(0, $len - 1)];
        }

        return "WIFI-{$p1}-{$p2}";
    }

    private function cacheVoucherInRedis(string $code, array $record): void
    {
        try {
            Redis::setex("hotspot:voucher:{$code}", 604800, json_encode($record)); // 7-day TTL
        } catch (\Throwable $e) {
            // Non-blocking fallback if Redis connection transiently hiccups
        }
    }

    private function getVoucherFromRedis(string $code): ?array
    {
        try {
            $data = Redis::get("hotspot:voucher:{$code}");

            return $data ? json_decode((string) $data, true) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
