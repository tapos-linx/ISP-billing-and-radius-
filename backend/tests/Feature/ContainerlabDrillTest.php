<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ContainerlabDrillTest extends TestCase
{
    use RefreshDatabase;

    public function test_containerlab_drill_command_runs_successfully(): void
    {
        Tariff::create([
            'name' => 'CHR 100M Dedicated',
            'download_speed' => 100,
            'upload_speed' => 100,
            'price_bdt' => 3500.00,
        ]);

        $exitCode = Artisan::call('carrier:clab-drill', ['--tenant-id' => 1]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('CONTAINERLAB VIRTUAL MIKROTIK CHR HARDWARE-IN-THE-LOOP TEST DRILL', $output);
        $this->assertStringContainsString('Auditing Containerlab Carrier Topology & RouterOS v7 Startup Manifests...', $output);
        $this->assertStringContainsString('Topology Manifest (clab-isp-carrier.yml): VALID', $output);
        $this->assertStringContainsString('RouterOS v7 Script (mikrotik-startup.rsc): VALID', $output);
        $this->assertStringContainsString('PPPoE Discovery Handshake: COMPLETED', $output);
        $this->assertStringContainsString('CONTAINERLAB MIKROTIK CHR DRILL PASSED', $output);
    }
}
