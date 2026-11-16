<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SubscriberStatus;
use App\Models\Nas;
use App\Models\Subscriber;
use App\Models\Tariff;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RadiusSyncTest extends TestCase
{
    use RefreshDatabase;

    private Nas $nas;

    private Tariff $defaultTariff;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure FreeRADIUS rlm_sql tables exist during isolated RefreshDatabase runs
        $this->ensureFreeRadiusTablesExist();

        $this->nas = Nas::query()->create([
            'nasname'      => '10.255.0.1',
            'shortname'    => 'DHK-UTTARA-BNG-01',
            'type'         => 'mikrotik',
            'ports'        => 3799,
            'secret'       => 'CarrierRadius#2026',
            'pop_division' => 'Dhaka',
            'is_active'    => true,
        ]);

        $this->defaultTariff = Tariff::query()->create([
            'code'                => 'PKG-50M-HOME',
            'name'                => '50 Mbps Super Sonic',
            'download_mbps'       => 50,
            'upload_mbps'         => 50,
            'mikrotik_rate_limit' => '50M/50M 60M/60M 40M/40M 10/10 8',
            'framed_pool'         => 'pppoe_active_pool',
            'monthly_price_bdt'   => 1050.00,
            'is_active'           => true,
        ]);
    }

    /**
     * Test 1: Assert that creating a new Subscriber via Eloquent successfully inserts
     * a record into the `radcheck` table with the attribute `Cleartext-Password`.
     */
    #[Test]
    public function it_inserts_cleartext_password_into_radcheck_when_subscriber_is_created(): void
    {
        $subscriber = Subscriber::query()->create([
            'username'       => 'tapos_uttara_01',
            'pppoe_password' => 'Pass#998877',
            'nas_id'         => $this->nas->id,
            'tariff_id'      => $this->defaultTariff->id,
            'status'         => SubscriberStatus::Active,
            'pop_division'   => 'Dhaka',
        ]);

        $this->assertDatabaseHas('radcheck', [
            'username'  => 'tapos_uttara_01',
            'attribute' => 'Cleartext-Password',
            'op'        => ':=',
            'value'     => 'Pass#998877',
        ]);

        $this->assertSame(
            1,
            DB::table('radcheck')
                ->where('username', $subscriber->username)
                ->where('attribute', 'Cleartext-Password')
                ->count()
        );
    }

    /**
     * Test 2: Assert that assigning a Tariff to a Subscriber inserts the correct
     * `Mikrotik-Rate-Limit` (and `Framed-Pool`) into the `radreply` table, and
     * switching to a higher Tariff updates `radreply` accordingly.
     */
    #[Test]
    public function it_inserts_and_updates_mikrotik_rate_limit_in_radreply_when_tariff_is_assigned(): void
    {
        $subscriber = Subscriber::query()->create([
            'username'       => 'tapos_tariff_02',
            'pppoe_password' => 'InitialSecret#01',
            'nas_id'         => $this->nas->id,
            'tariff_id'      => $this->defaultTariff->id,
            'status'         => SubscriberStatus::Active,
            'pop_division'   => 'Dhaka',
        ]);

        // Verify initial Tariff rate-limit and pool in radreply
        $this->assertDatabaseHas('radreply', [
            'username'  => 'tapos_tariff_02',
            'attribute' => 'Mikrotik-Rate-Limit',
            'op'        => ':=',
            'value'     => '50M/50M 60M/60M 40M/40M 10/10 8',
        ]);

        $this->assertDatabaseHas('radreply', [
            'username'  => 'tapos_tariff_02',
            'attribute' => 'Framed-Pool',
            'op'        => ':=',
            'value'     => 'pppoe_active_pool',
        ]);

        // Create and assign a new 100 Mbps Tariff
        $turboTariff = Tariff::query()->create([
            'code'                => 'PKG-100M-GAMER',
            'name'                => '100 Mbps Carrier Pro',
            'download_mbps'       => 100,
            'upload_mbps'         => 100,
            'mikrotik_rate_limit' => '100M/100M 120M/120M 80M/80M 12/12 4',
            'framed_pool'         => 'pppoe_gamer_pool',
            'monthly_price_bdt'   => 1850.00,
            'is_active'           => true,
        ]);

        $subscriber->update([
            'tariff_id' => $turboTariff->id,
        ]);

        // Assert radreply now reflects the new Tariff without duplicate rows
        $this->assertDatabaseHas('radreply', [
            'username'  => 'tapos_tariff_02',
            'attribute' => 'Mikrotik-Rate-Limit',
            'op'        => ':=',
            'value'     => '100M/100M 120M/120M 80M/80M 12/12 4',
        ]);

        $this->assertDatabaseHas('radreply', [
            'username'  => 'tapos_tariff_02',
            'attribute' => 'Framed-Pool',
            'op'        => ':=',
            'value'     => 'pppoe_gamer_pool',
        ]);

        $this->assertSame(
            1,
            DB::table('radreply')
                ->where('username', 'tapos_tariff_02')
                ->where('attribute', 'Mikrotik-Rate-Limit')
                ->count()
        );
    }

    /**
     * Test 3: Assert that updating a Subscriber's password updates the existing
     * `radcheck` row rather than duplicating it.
     */
    #[Test]
    public function it_updates_existing_radcheck_row_without_duplication_when_password_changes(): void
    {
        $subscriber = Subscriber::query()->create([
            'username'       => 'tapos_passwd_03',
            'pppoe_password' => 'OldPassword#2025',
            'nas_id'         => $this->nas->id,
            'tariff_id'      => $this->defaultTariff->id,
            'status'         => SubscriberStatus::Active,
            'pop_division'   => 'Dhaka',
        ]);

        $initialRadcheckId = DB::table('radcheck')
            ->where('username', 'tapos_passwd_03')
            ->where('attribute', 'Cleartext-Password')
            ->value('id');

        $this->assertNotNull($initialRadcheckId);

        // Update subscriber's PPPoE password via Eloquent
        $subscriber->update([
            'pppoe_password' => 'NewRotatedPass#2026',
        ]);

        // Assert new password is saved in radcheck
        $this->assertDatabaseHas('radcheck', [
            'id'        => $initialRadcheckId,
            'username'  => 'tapos_passwd_03',
            'attribute' => 'Cleartext-Password',
            'op'        => ':=',
            'value'     => 'NewRotatedPass#2026',
        ]);

        // Assert old password no longer exists
        $this->assertDatabaseMissing('radcheck', [
            'username'  => 'tapos_passwd_03',
            'attribute' => 'Cleartext-Password',
            'value'     => 'OldPassword#2025',
        ]);

        // Assert strictly 1 row exists in radcheck for this subscriber's Cleartext-Password
        $rowCount = DB::table('radcheck')
            ->where('username', 'tapos_passwd_03')
            ->where('attribute', 'Cleartext-Password')
            ->count();

        $this->assertSame(1, $rowCount);
    }

    /**
     * Test 4 (Carrier Walled Garden Enforcement): Assert that suspending or expiring
     * a subscriber automatically switches `Framed-Pool` to `walled_garden_pool`
     * and throttles `Mikrotik-Rate-Limit` to `512k/512k`.
     */
    #[Test]
    public function it_switches_radreply_to_walled_garden_when_subscriber_is_suspended_or_expired(): void
    {
        $subscriber = Subscriber::query()->create([
            'username'       => 'tapos_walled_04',
            'pppoe_password' => 'Pass#Walled04',
            'nas_id'         => $this->nas->id,
            'tariff_id'      => $this->defaultTariff->id,
            'status'         => SubscriberStatus::Active,
            'pop_division'   => 'Dhaka',
        ]);

        $subscriber->update([
            'status' => SubscriberStatus::Expired,
        ]);

        $this->assertDatabaseHas('radreply', [
            'username'  => 'tapos_walled_04',
            'attribute' => 'Mikrotik-Rate-Limit',
            'op'        => ':=',
            'value'     => '512k/512k',
        ]);

        $this->assertDatabaseHas('radreply', [
            'username'  => 'tapos_walled_04',
            'attribute' => 'Framed-Pool',
            'op'        => ':=',
            'value'     => 'walled_garden_pool',
        ]);
    }

    /**
     * Helper to guarantee `radcheck` and `radreply` tables exist in the test database
     * even if only the 4 core entity migrations are executed.
     */
    private function ensureFreeRadiusTablesExist(): void
    {
        if (! Schema::hasTable('radcheck')) {
            Schema::create('radcheck', function (Blueprint $table): void {
                $table->id();
                $table->string('username', 64)->default('')->index();
                $table->string('attribute', 64)->default('');
                $table->char('op', 2)->default('==');
                $table->string('value', 253)->default('');
            });
        }

        if (! Schema::hasTable('radreply')) {
            Schema::create('radreply', function (Blueprint $table): void {
                $table->id();
                $table->string('username', 64)->default('')->index();
                $table->string('attribute', 64)->default('');
                $table->char('op', 2)->default('=');
                $table->string('value', 253)->default('');
            });
        }
    }
}
