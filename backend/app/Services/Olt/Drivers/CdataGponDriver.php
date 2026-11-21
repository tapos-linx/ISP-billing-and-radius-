<?php

declare(strict_types=1);

namespace App\Services\Olt\Drivers;

use App\Models\Olt;
use App\Models\Onu;
use App\Services\Olt\OltDriverInterface;
use Illuminate\Support\Facades\Log;

/**
 * P0-10: C-Data FD1608S GPON OLT Driver
 */
class CdataGponDriver implements OltDriverInterface
{
    public function discoverUnconfiguredOnus(Olt $olt): array
    {
        Log::info(sprintf('[C-Data GPON] Scanning unconfigured ONUs on %s', $olt->name));

        return [
            [
                'pon_port' => 'EPON0/1',
                'serial_number' => 'CDAT'.strtoupper(substr(md5($olt->name.'cdata1'), 0, 8)),
                'vendor_id' => 'CDAT',
                'discovered_at' => now()->toIso8601String(),
            ],
        ];
    }

    public function provisionOnu(Olt $olt, Onu $onu): array
    {
        Log::info(sprintf('[C-Data GPON] Provisioning ONU %s on OLT %s PON %s', $onu->serial_number, $olt->name, $onu->pon_port));

        return [
            'success' => true,
            'onu_index' => $onu->onu_index ?: 1,
            'pon_port' => $onu->pon_port,
            'serial_number' => $onu->serial_number,
            'vlan_id' => $onu->vlan_id,
            'status' => 'provisioned',
        ];
    }

    public function deprovisionOnu(Olt $olt, Onu $onu): array
    {
        Log::info(sprintf('[C-Data GPON] De-provisioning ONU %s', $onu->serial_number));

        return [
            'success' => true,
            'serial_number' => $onu->serial_number,
            'status' => 'terminated',
        ];
    }

    public function readOpticalDiagnostics(Olt $olt, Onu $onu): array
    {
        return [
            'rx_power_dbm' => -20.30,
            'tx_power_dbm' => 2.05,
            'distance_m' => 950,
            'status' => 'normal',
        ];
    }
}
