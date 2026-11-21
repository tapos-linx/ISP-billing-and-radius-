<?php

declare(strict_types=1);

namespace App\Services\Olt;

use App\Models\Olt;
use App\Models\Onu;

/**
 * P0-10: Vendor Agnostic GPON / EPON OLT Driver Interface
 */
interface OltDriverInterface
{
    /**
     * Discover unconfigured (auto-found) ONUs on all PON ports
     *
     * @return array<int, array{
     *   pon_port: string,
     *   serial_number: string,
     *   vendor_id: string,
     *   discovered_at: string
     * }>
     */
    public function discoverUnconfiguredOnus(Olt $olt): array;

    /**
     * Provision and activate an ONU on a given PON port and VLAN
     *
     * @return array{
     *   success: bool,
     *   onu_index: int,
     *   pon_port: string,
     *   serial_number: string,
     *   vlan_id: int,
     *   status: string
     * }
     */
    public function provisionOnu(Olt $olt, Onu $onu): array;

    /**
     * De-provision and remove ONU on service termination
     *
     * @return array{
     *   success: bool,
     *   serial_number: string,
     *   status: string
     * }
     */
    public function deprovisionOnu(Olt $olt, Onu $onu): array;

    /**
     * Read live optical power diagnostics (Rx / Tx dBm, distance, temperature)
     *
     * @return array{
     *   rx_power_dbm: float,
     *   tx_power_dbm: float,
     *   distance_m: int,
     *   status: string
     * }
     */
    public function readOpticalDiagnostics(Olt $olt, Onu $onu): array;
}
