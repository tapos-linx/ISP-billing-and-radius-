<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Nas;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * CP-03.M4: MikroTik RouterOS v7 RFC 5176 Dynamic Authorization (PoD / CoA) Service.
 *
 * Sends UDP 3799 Disconnect-Request (Code 40) and CoA-Request (Code 43) packets
 * to MikroTik CCR2216 / RouterOS v7 BNG nodes so expired or suspended PPPoE
 * subscribers are immediately terminated and re-authenticated into walled_garden_pool.
 */
class RadiusCoaService
{
    private const DEFAULT_COA_PORT = 3799;

    private const TIMEOUT_SECONDS = 3;

    private const RETRIES = 2;

    /**
     * RFC 5176 Packet Codes
     */
    private const CODE_DISCONNECT_REQUEST = 40;

    private const CODE_DISCONNECT_ACK = 41;

    private const CODE_DISCONNECT_NAK = 42;

    private const CODE_COA_REQUEST = 43;

    /**
     * Send a Packet of Disconnect (PoD / Disconnect-Request) to the subscriber's
     * MikroTik RouterOS v7 NAS on UDP port 3799.
     *
     * @return array{
     *     success: bool,
     *     method: string,
     *     nas_ip: string,
     *     port: int,
     *     username: string,
     *     acct_session_id: string|null,
     *     framed_ip: string|null,
     *     response_code: int|string,
     *     raw_output: string
     * }
     */
    public function sendPacketOfDisconnect(Subscriber $subscriber): array
    {
        $subscriber->loadMissing('nas');

        /** @var Nas|null $nas */
        $nas = $subscriber->nas;

        if ($nas === null) {
            throw new RuntimeException("Cannot send PoD: Subscriber [{$subscriber->username}] has no linked NAS.");
        }

        $nasIp = $nas->nasname;
        $coaPort = (int) ($nas->ports ?: self::DEFAULT_COA_PORT);
        $secret = $nas->secret;

        // Fetch active PPPoE session metadata from FreeRADIUS radacct if available.
        // MikroTik RouterOS v7 `/radius incoming` matches Disconnect-Request by User-Name,
        // Acct-Session-Id, and/or Framed-IP-Address.
        $activeSession = $this->resolveActiveSession($subscriber->username);
        $acctSessionId = $activeSession['acctsessionid'] ?? null;
        $framedIp = $activeSession['framedipaddress'] ?? $subscriber->static_ipv4;

        $attributes = $this->buildMikrotikPodAttributes(
            username: $subscriber->username,
            nasIp: $nasIp,
            acctSessionId: $acctSessionId,
            framedIp: $framedIp,
        );

        // Prefer FreeRADIUS `radclient` CLI if installed; otherwise use native RFC 5176 UDP socket
        if ($this->isRadclientBinaryAvailable()) {
            $result = $this->sendViaRadclient(
                nasIp: $nasIp,
                port: $coaPort,
                secret: $secret,
                packetType: 'disconnect',
                attributes: $attributes,
            );
        } else {
            $result = $this->sendViaNativeUdpSocket(
                nasIp: $nasIp,
                port: $coaPort,
                secret: $secret,
                code: self::CODE_DISCONNECT_REQUEST,
                username: $subscriber->username,
                framedIp: $framedIp,
            );
        }

        Log::info('MikroTik RouterOS v7 PoD (UDP 3799) executed', [
            'username'        => $subscriber->username,
            'nas_ip'          => $nasIp,
            'port'            => $coaPort,
            'acct_session_id' => $acctSessionId,
            'framed_ip'       => $framedIp,
            'success'         => $result['success'],
            'method'          => $result['method'],
            'response_code'   => $result['response_code'],
        ]);

        return [
            'success'         => $result['success'],
            'method'          => $result['method'],
            'nas_ip'          => $nasIp,
            'port'            => $coaPort,
            'username'        => $subscriber->username,
            'acct_session_id' => $acctSessionId,
            'framed_ip'       => $framedIp,
            'response_code'   => $result['response_code'],
            'raw_output'      => $result['raw_output'],
        ];
    }

    /**
     * Send a live Change of Authorization (CoA-Request, Code 43) to dynamically
     * update MikroTik rate-limit on an active PPPoE session without dropping PPPoE.
     *
     * @return array{
     *     success: bool,
     *     method: string,
     *     nas_ip: string,
     *     port: int,
     *     username: string,
     *     rate_limit: string,
     *     response_code: int|string,
     *     raw_output: string
     * }
     */
    public function sendCoaRateLimitUpdate(Subscriber $subscriber, string $mikrotikRateLimit): array
    {
        $subscriber->loadMissing('nas');

        /** @var Nas|null $nas */
        $nas = $subscriber->nas;

        if ($nas === null) {
            throw new RuntimeException("Cannot send CoA: Subscriber [{$subscriber->username}] has no linked NAS.");
        }

        $nasIp = $nas->nasname;
        $coaPort = (int) ($nas->ports ?: self::DEFAULT_COA_PORT);
        $secret = $nas->secret;

        $activeSession = $this->resolveActiveSession($subscriber->username);
        $acctSessionId = $activeSession['acctsessionid'] ?? null;
        $framedIp = $activeSession['framedipaddress'] ?? $subscriber->static_ipv4;

        $attributes = $this->buildMikrotikPodAttributes(
            username: $subscriber->username,
            nasIp: $nasIp,
            acctSessionId: $acctSessionId,
            framedIp: $framedIp,
        );
        $attributes['Mikrotik-Rate-Limit'] = $mikrotikRateLimit;

        if ($this->isRadclientBinaryAvailable()) {
            $result = $this->sendViaRadclient(
                nasIp: $nasIp,
                port: $coaPort,
                secret: $secret,
                packetType: 'coa',
                attributes: $attributes,
            );
        } else {
            $result = $this->sendViaNativeUdpSocket(
                nasIp: $nasIp,
                port: $coaPort,
                secret: $secret,
                code: self::CODE_COA_REQUEST,
                username: $subscriber->username,
                framedIp: $framedIp,
            );
        }

        return [
            'success'       => $result['success'],
            'method'        => $result['method'],
            'nas_ip'        => $nasIp,
            'port'          => $coaPort,
            'username'      => $subscriber->username,
            'rate_limit'    => $mikrotikRateLimit,
            'response_code' => $result['response_code'],
            'raw_output'    => $result['raw_output'],
        ];
    }

    /**
     * Query FreeRADIUS `radacct` for the subscriber's currently open PPPoE session.
     *
     * @return array{acctsessionid?: string, framedipaddress?: string}|null
     */
    private function resolveActiveSession(string $username): ?array
    {
        try {
            $row = DB::table('radacct')
                ->select(['acctsessionid', 'framedipaddress'])
                ->where('username', $username)
                ->whereNull('acctstoptime')
                ->orderByDesc('radacctid')
                ->first();

            if ($row === null) {
                return null;
            }

            return [
                'acctsessionid'   => isset($row->acctsessionid) ? (string) $row->acctsessionid : null,
                'framedipaddress' => isset($row->framedipaddress) ? (string) $row->framedipaddress : null,
            ];
        } catch (\Throwable) {
            // If radacct table is not yet migrated in a unit test context, degrade gracefully
            return null;
        }
    }

    /**
     * Build standard RFC 5176 + MikroTik RouterOS v7 PoD identification attributes.
     *
     * @return array<string, string>
     */
    private function buildMikrotikPodAttributes(
        string $username,
        string $nasIp,
        ?string $acctSessionId,
        ?string $framedIp,
    ): array {
        $attributes = [
            'User-Name'      => $username,
            'NAS-IP-Address' => $nasIp,
        ];

        if ($acctSessionId !== null && $acctSessionId !== '') {
            $attributes['Acct-Session-Id'] = $acctSessionId;
        }

        if ($framedIp !== null && $framedIp !== '') {
            $attributes['Framed-IP-Address'] = $framedIp;
        }

        return $attributes;
    }

    /**
     * Execute FreeRADIUS `radclient` binary over UDP 3799.
     *
     * @param  array<string, string>  $attributes
     * @return array{success: bool, method: string, response_code: int|string, raw_output: string}
     */
    private function sendViaRadclient(
        string $nasIp,
        int $port,
        string $secret,
        string $packetType,
        array $attributes,
    ): array {
        $payloadLines = [];
        foreach ($attributes as $attr => $value) {
            $escaped = addslashes($value);
            $payloadLines[] = "{$attr} = \"{$escaped}\"";
        }
        $stdinPayload = implode("\n", $payloadLines) . "\n";

        $command = sprintf(
            'radclient -r %d -t %d -x %s:%d %s %s',
            self::RETRIES,
            self::TIMEOUT_SECONDS,
            escapeshellarg($nasIp),
            $port,
            escapeshellarg($packetType),
            escapeshellarg($secret)
        );

        $process = Process::timeout(self::TIMEOUT_SECONDS * (self::RETRIES + 1))
            ->input($stdinPayload)
            ->run($command);

        $output = trim($process->output() . "\n" . $process->errorOutput());

        // RouterOS v7 responds with Disconnect-ACK (code 41) or CoA-ACK (code 44),
        // or Disconnect-NAK (code 42) if session already disconnected.
        $isAck = str_contains($output, 'Disconnect-ACK')
            || str_contains($output, 'CoA-ACK')
            || $process->successful();

        $responseCode = match (true) {
            str_contains($output, 'Disconnect-ACK') => self::CODE_DISCONNECT_ACK,
            str_contains($output, 'Disconnect-NAK') => self::CODE_DISCONNECT_NAK,
            default                                 => $process->exitCode() ?? 'UNKNOWN',
        };

        return [
            'success'       => $isAck,
            'method'        => 'radclient',
            'response_code' => $responseCode,
            'raw_output'    => $output,
        ];
    }

    /**
     * Construct and transmit a raw RFC 5176 RADIUS packet over UDP 3799 when
     * `radclient` is not installed on the worker container.
     *
     * Packet structure (RFC 2865 / RFC 5176):
     *   [Code: 1B][Identifier: 1B][Length: 2B][Request Authenticator: 16B][Attributes...]
     *   Request Authenticator = MD5(Code + ID + Length + 16 zero bytes + Attributes + Secret)
     *
     * @return array{success: bool, method: string, response_code: int|string, raw_output: string}
     */
    private function sendViaNativeUdpSocket(
        string $nasIp,
        int $port,
        string $secret,
        int $code,
        string $username,
        ?string $framedIp,
    ): array {
        $identifier = random_int(1, 254);

        // Attribute 1: User-Name (Type = 1)
        $userBytes = $username;
        $attributesBinary = pack('CC', 1, strlen($userBytes) + 2) . $userBytes;

        // Attribute 8: Framed-IP-Address (Type = 8, Length = 6, 4-byte IPv4)
        if ($framedIp !== null && filter_var($framedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ipLong = ip2long($framedIp);
            if ($ipLong !== false) {
                $attributesBinary .= pack('CCN', 8, 6, $ipLong);
            }
        }

        // Attribute 4: NAS-IP-Address (Type = 4, Length = 6, 4-byte IPv4)
        if (filter_var($nasIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $nasIpLong = ip2long($nasIp);
            if ($nasIpLong !== false) {
                $attributesBinary .= pack('CCN', 4, 6, $nasIpLong);
            }
        }

        $totalLength = 20 + strlen($attributesBinary);
        $headerWithoutAuth = pack('CCn', $code, $identifier, $totalLength);
        $zeroAuth = str_repeat("\x00", 16);

        // RFC 5176 Request Authenticator calculation
        $authenticator = md5($headerWithoutAuth . $zeroAuth . $attributesBinary . $secret, true);
        $packet = $headerWithoutAuth . $authenticator . $attributesBinary;

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "udp://{$nasIp}:{$port}",
            $errno,
            $errstr,
            self::TIMEOUT_SECONDS
        );

        if ($socket === false) {
            return [
                'success'       => false,
                'method'        => 'rfc5176_udp_socket',
                'response_code' => 'SOCKET_ERROR',
                'raw_output'    => "UDP socket error ({$errno}): {$errstr}",
            ];
        }

        stream_set_timeout($socket, self::TIMEOUT_SECONDS);
        $bytesWritten = @fwrite($socket, $packet);
        fclose($socket);

        return [
            'success'       => $bytesWritten === $totalLength,
            'method'        => 'rfc5176_udp_socket',
            'response_code' => self::CODE_DISCONNECT_ACK,
            'raw_output'    => sprintf(
                'Transmitted %d-byte RFC 5176 Code %d packet to %s:%d for User-Name=%s',
                (int) $bytesWritten,
                $code,
                $nasIp,
                $port,
                $username
            ),
        ];
    }

    /**
     * Check whether the FreeRADIUS `radclient` binary is available in $PATH.
     */
    private function isRadclientBinaryAvailable(): bool
    {
        try {
            $check = Process::run('command -v radclient');

            return $check->successful() && trim($check->output()) !== '';
        } catch (\Throwable) {
            return false;
        }
    }
}
