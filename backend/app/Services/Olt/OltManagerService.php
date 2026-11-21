<?php

declare(strict_types=1);

namespace App\Services\Olt;

use App\Models\Olt;
use App\Models\Onu;
use App\Services\Olt\Drivers\CdataGponDriver;
use App\Services\Olt\Drivers\HuaweiGponDriver;
use App\Services\Olt\Drivers\VsolGponDriver;
use App\Services\Olt\Drivers\ZteGponDriver;
use InvalidArgumentException;

/**
 * P0-10: Multi-Vendor OLT Provisioning & Telemetry Manager
 */
class OltManagerService
{
    public function getDriver(string $vendor): OltDriverInterface
    {
        return match (strtolower($vendor)) {
            'huawei' => app(HuaweiGponDriver::class),
            'zte' => app(ZteGponDriver::class),
            'vsol' => app(VsolGponDriver::class),
            'cdata' => app(CdataGponDriver::class),
            default => throw new InvalidArgumentException("Unsupported OLT vendor: {$vendor}"),
        };
    }

    public function discoverOnus(Olt $olt): array
    {
        return $this->getDriver($olt->vendor)->discoverUnconfiguredOnus($olt);
    }

    public function provision(Olt $olt, Onu $onu): array
    {
        $result = $this->getDriver($olt->vendor)->provisionOnu($olt, $onu);

        if ($result['success'] ?? false) {
            $onu->status = 'provisioned';
            $onu->onu_index = $result['onu_index'] ?? 1;
            $onu->save();
        }

        return $result;
    }

    public function deprovision(Olt $olt, Onu $onu): array
    {
        $result = $this->getDriver($olt->vendor)->deprovisionOnu($olt, $onu);

        if ($result['success'] ?? false) {
            $onu->status = 'terminated';
            $onu->save();
        }

        return $result;
    }

    public function getOpticalPower(Olt $olt, Onu $onu): array
    {
        $diag = $this->getDriver($olt->vendor)->readOpticalDiagnostics($olt, $onu);

        $onu->rx_power_dbm = $diag['rx_power_dbm'];
        $onu->tx_power_dbm = $diag['tx_power_dbm'];
        $onu->distance_m = $diag['distance_m'];
        $onu->last_seen_at = now();
        $onu->save();

        return $diag;
    }
}
