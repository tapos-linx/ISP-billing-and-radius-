<?php

declare(strict_types=1);

namespace App\Services\Cpe;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * P1-6: TR-069 CWMP NBI (Northbound Interface) Client for GenieACS
 * Port 7557 REST API integration for automated CPE remote provisioning,
 * Wi-Fi SSID/password orchestration, and WAN PPPoE configuration.
 */
class GenieAcsClient
{
    protected string $baseUrl;

    protected int $timeout;

    public function __construct(?string $baseUrl = null, int $timeout = 5)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.genieacs.nbi_url', 'http://127.0.0.1:7557'), '/');
        $this->timeout = $timeout;
    }

    /**
     * Query a CPE device by serial number or MAC address
     *
     * @return array<string, mixed>|null
     */
    public function getDevice(string $deviceIdOrSerial): ?array
    {
        try {
            $query = json_encode(['_id' => $deviceIdOrSerial]);
            $response = Http::timeout($this->timeout)
                ->get("{$this->baseUrl}/devices", [
                    'query' => $query,
                ]);

            if ($response->successful()) {
                /** @var array<int, array<string, mixed>> $devices */
                $devices = $response->json();
                if (! empty($devices) && isset($devices[0])) {
                    return $devices[0];
                }
            }

            // Fallback: direct ID path lookup
            $directResp = Http::timeout($this->timeout)->get("{$this->baseUrl}/devices/{$deviceIdOrSerial}");
            if ($directResp->successful()) {
                /** @var array<string, mixed> $data */
                $data = $directResp->json();

                return $data;
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('GenieACS getDevice failed', ['device' => $deviceIdOrSerial, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Dispatch an immediate remote reboot task to the CPE
     *
     * @return array<string, mixed>
     */
    public function rebootDevice(string $deviceId): array
    {
        return $this->dispatchTask($deviceId, [
            'name' => 'reboot',
        ]);
    }

    /**
     * Push Wi-Fi SSID and WPA2/WPA3 Pre-Shared Key to the CPE via TR-069
     *
     * @return array<string, mixed>
     */
    public function configureWifi(string $deviceId, string $ssid, string $password): array
    {
        $parameters = [
            ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID', $ssid, 'xsd:string'],
            ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase', $password, 'xsd:string'],
            ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.Enable', 'true', 'xsd:boolean'],
        ];

        return $this->setParameterValues($deviceId, $parameters);
    }

    /**
     * Provision WAN PPPoE credentials and 802.1Q VLAN tag onto the subscriber ONU/ONT
     *
     * @return array<string, mixed>
     */
    public function provisionWanPppoe(string $deviceId, string $username, string $password, int $vlanId = 100): array
    {
        $parameters = [
            ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Enable', 'true', 'xsd:boolean'],
            ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username', $username, 'xsd:string'],
            ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Password', $password, 'xsd:string'],
            ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ConnectionType', 'IP_Routed', 'xsd:string'],
            ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.X_BROADCOM_COM_VLANID', (string) $vlanId, 'xsd:unsignedInt'],
        ];

        return $this->setParameterValues($deviceId, $parameters);
    }

    /**
     * Execute setParameterValues RPC on the specified device
     *
     * @param  array<int, array<int, string>>  $parameters
     * @return array<string, mixed>
     */
    public function setParameterValues(string $deviceId, array $parameters): array
    {
        return $this->dispatchTask($deviceId, [
            'name' => 'setParameterValues',
            'parameterValues' => $parameters,
        ]);
    }

    /**
     * Queue and trigger a TR-069 NBI task with immediate connection request
     *
     * @param  array<string, mixed>  $taskPayload
     * @return array<string, mixed>
     */
    protected function dispatchTask(string $deviceId, array $taskPayload): array
    {
        try {
            /** @var Response $response */
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/devices/{$deviceId}/tasks?connection_request", $taskPayload);

            if ($response->successful()) {
                /** @var array<string, mixed> $body */
                $body = $response->json() ?? [];

                return [
                    'success' => true,
                    'status' => $response->status(),
                    'task' => $body,
                ];
            }

            return [
                'success' => false,
                'status' => $response->status(),
                'error' => $response->body(),
            ];
        } catch (\Throwable $e) {
            Log::error('GenieACS task dispatch error', [
                'device' => $deviceId,
                'task' => $taskPayload['name'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 500,
                'error' => $e->getMessage(),
            ];
        }
    }
}
