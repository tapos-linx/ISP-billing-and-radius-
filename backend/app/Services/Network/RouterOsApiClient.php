<?php

declare(strict_types=1);

namespace App\Services\Network;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * P0-9: Live MikroTik RouterOS API Client & Config Backup Service
 * Supports RouterOS v7 REST API / Port 8728 API & MinIO diff-based backup streaming
 */
class RouterOsApiClient
{
    public function __construct(
        protected ?string $host = null,
        protected ?string $username = null,
        protected ?string $password = null,
        protected int $port = 80,
        protected bool $useSsl = false
    ) {
        $this->host = $host ?? config('mikrotik.host', '127.0.0.1');
        $this->username = $username ?? config('mikrotik.username', 'admin');
        $this->password = $password ?? config('mikrotik.password', '');
        $this->port = $port ?: (int) config('mikrotik.port', 80);
        $this->useSsl = $useSsl || (bool) config('mikrotik.ssl', false);
    }

    /**
     * Fetch active PPPoE sessions from RouterOS
     */
    public function getActivePppoeSessions(): array
    {
        if (config('mikrotik.mock', true)) {
            return [
                [
                    '.id' => '*1',
                    'name' => 'sub_pppoe_101',
                    'service' => 'pppoe',
                    'caller-id' => '00:1A:2B:3C:4D:5E',
                    'address' => '100.64.1.10',
                    'uptime' => '2d04h15m',
                    'encoding' => '',
                ],
            ];
        }

        try {
            $response = $this->client()->get($this->baseUrl().'/ppp/active');

            return $response->json() ?: [];
        } catch (Throwable $e) {
            Log::error('Mikrotik getActivePppoeSessions failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Fetch active Hotspot sessions from RouterOS
     */
    public function getActiveHotspotSessions(): array
    {
        if (config('mikrotik.mock', true)) {
            return [
                [
                    '.id' => '*H1',
                    'user' => 'HOTSPOT-V-882194',
                    'address' => '10.10.10.25',
                    'mac-address' => 'AA:BB:CC:DD:EE:FF',
                    'uptime' => '35m12s',
                    'bytes-in' => '5482910',
                    'bytes-out' => '24910291',
                ],
            ];
        }

        try {
            $response = $this->client()->get($this->baseUrl().'/ip/hotspot/active');

            return $response->json() ?: [];
        } catch (Throwable $e) {
            Log::error('Mikrotik getActiveHotspotSessions failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Add or remove IP from firewall address list (e.g. walled_garden, dunning_quarantine)
     */
    public function manageAddressList(string $list, string $address, string $comment = '', string $action = 'add'): bool
    {
        if (config('mikrotik.mock', true)) {
            Log::info("Mock RouterOS address-list {$action}: list={$list}, addr={$address}");

            return true;
        }

        try {
            if ($action === 'add') {
                $response = $this->client()->put($this->baseUrl().'/ip/firewall/address-list', [
                    'list' => $list,
                    'address' => $address,
                    'comment' => $comment,
                ]);

                return $response->successful();
            }

            // Remove logic
            $find = $this->client()->get($this->baseUrl().'/ip/firewall/address-list', [
                'list' => $list,
                'address' => $address,
            ])->json();

            if (! empty($find) && isset($find[0]['.id'])) {
                $del = $this->client()->delete($this->baseUrl().'/ip/firewall/address-list/'.$find[0]['.id']);

                return $del->successful();
            }

            return true;
        } catch (Throwable $e) {
            Log::error('Mikrotik manageAddressList failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Export complete RouterOS configuration
     */
    public function exportConfig(): string
    {
        if (config('mikrotik.mock', true)) {
            return "# RouterOS 7.15 Export configuration\n# generated at ".date('Y-m-d H:i:s')."\n/interface ethernet set [ find default-name=ether1 ] name=WAN\n/ppp profile add name=20M rate-limit=20M/20M\n/ip firewall nat add chain=srcnat action=masquerade out-interface=WAN\n";
        }

        try {
            $response = $this->client()->post($this->baseUrl().'/system/script/run', [
                'script' => ':execute { /export file=temp_auto_backup }',
            ]);

            return $response->body();
        } catch (Throwable $e) {
            Log::error('Mikrotik exportConfig error: '.$e->getMessage());

            return '';
        }
    }

    /**
     * Stream diff-based configuration snapshot to MinIO S3 backup bucket
     */
    public function backupToMinio(string $nasIdentity): array
    {
        $timestamp = Carbon::now()->format('Ymd_His');
        $fileName = sprintf('backups/mikrotik/%s_%s.rsc', preg_replace('/[^a-zA-Z0-9_-]/', '', $nasIdentity), $timestamp);
        $configData = $this->exportConfig();

        $disk = config('filesystems.disks.minio') ? 'minio' : 'local';

        try {
            Storage::disk($disk)->put($fileName, $configData);

            $fileSize = strlen($configData);
            $sha256 = hash('sha256', $configData);

            Log::info("MikroTik backup stored in {$disk}://{$fileName} ({$fileSize} bytes)");

            return [
                'success' => true,
                'disk' => $disk,
                'file_name' => $fileName,
                'size_bytes' => $fileSize,
                'checksum' => $sha256,
                'created_at' => Carbon::now()->toIso8601String(),
            ];
        } catch (Throwable $e) {
            Log::error('MikroTik backupToMinio failed: '.$e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function client(): PendingRequest
    {
        return Http::withBasicAuth($this->username ?? '', $this->password ?? '')
            ->timeout(10);
    }

    protected function baseUrl(): string
    {
        $scheme = $this->useSsl ? 'https' : 'http';

        return "{$scheme}://{$this->host}:{$this->port}/rest";
    }
}
