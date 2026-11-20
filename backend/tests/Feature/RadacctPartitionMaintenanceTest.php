<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RadacctPartitionMaintenanceTest extends TestCase
{
    public function test_artisan_command_creates_future_partitions(): void
    {
        $exitCode = Artisan::call('radacct:maintain-partitions', [
            '--ahead'     => 6,
            '--retention' => 24,
        ]);

        $this->assertEquals(0, $exitCode);
    }

    public function test_future_monthly_partition_accepts_radacct_insert(): void
    {
        $futureTime = date('Y-m-05 12:00:00+00', strtotime('+2 months'));
        $username = 'test_part_user_' . bin2hex(random_bytes(4));

        // Ensure partition maintenance function runs
        DB::statement("SELECT * FROM manage_radacct_monthly_partitions(3, 24);");

        // Insert into radacct with future timestamp
        $inserted = DB::table('radacct')->insert([
            'acctsessionid' => 'sess_' . bin2hex(random_bytes(6)),
            'username'      => $username,
            'nasipaddress'  => '127.0.0.1',
            'acctstarttime' => $futureTime,
            'tenant_id'     => 1,
        ]);

        $this->assertTrue($inserted);

        // Verify row exists
        $row = DB::table('radacct')->where('username', $username)->first();
        $this->assertNotNull($row);
        $this->assertEquals($username, $row->username);

        // Cleanup
        DB::table('radacct')->where('username', $username)->delete();
    }
}
