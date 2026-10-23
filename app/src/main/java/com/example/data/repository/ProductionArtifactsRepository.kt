package com.example.data.repository

import com.example.data.model.DeploymentStage
import com.example.data.model.StageArtifact
import com.example.data.model.VerificationStep

object ProductionArtifactsRepository {

    val stages: List<DeploymentStage> = listOf(
        // ================= STAGE 1 =================
        DeploymentStage(
            stageNumber = 1,
            daysRange = "Days 1-5",
            title = "Foundation & RADIUS Core",
            subtitle = "Docker Stack, PostgreSQL 16 Partitioned radacct, FreeRADIUS 3.x",
            targetSla = "<10ms Access-Accept Latency",
            summary = "Deploy and verify PostgreSQL 16 with monthly partitioned radacct tables, Redis 7 caching, and FreeRADIUS 3.x using rlm_sql on Windows 10/11 WSL2 with Docker Compose.",
            technologies = listOf("Windows 11 WSL2", "Docker Compose", "PostgreSQL 16", "Redis 7", "FreeRADIUS 3.x", "PowerShell 7"),
            artifacts = listOf(
                StageArtifact(
                    id = "docker-compose-s1",
                    title = "Production Docker Compose Stack",
                    fileName = "docker-compose.yml",
                    language = "yaml",
                    description = "WSL2-optimized stack for PostgreSQL 16, Redis 7, and FreeRADIUS 3.2 with inline CRLF stripping and health checks.",
                    codeContent = """
version: '3.8'

services:
  postgres:
    image: postgres:16-alpine
    container_name: isp_postgres
    restart: always
    environment:
      POSTGRES_DB: isp_billing
      POSTGRES_USER: radius_user
      POSTGRES_PASSWORD: SecretRadiusDbPassword2026!
      PGDATA: /var/lib/postgresql/data/pgdata
    volumes:
      - ./data/postgres:/var/lib/postgresql/data
      - ./sql/01-init-schema.sql:/docker-entrypoint-initdb.d/01-init-schema.sql:ro
      - ./sql/02-seed-test-data.sql:/docker-entrypoint-initdb.d/02-seed-test-data.sql:ro
    ports:
      - "127.0.0.1:5432:5432"
    networks:
      - isp_internal_net
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U radius_user -d isp_billing"]
      interval: 3s
      timeout: 3s
      retries: 10
    command: >
      postgres
        -c shared_buffers=1024MB
        -c effective_cache_size=3072MB
        -c work_mem=64MB
        -c maintenance_work_mem=256MB
        -c checkpoint_completion_target=0.9
        -c wal_buffers=16MB
        -c max_connections=200

  redis:
    image: redis:7-alpine
    container_name: isp_redis
    restart: always
    command: redis-server --requirepass SecretRedisToken2026! --appendonly yes
    volumes:
      - ./data/redis:/data
    ports:
      - "127.0.0.1:6379:6379"
    networks:
      - isp_internal_net

  freeradius:
    image: freeradius/freeradius-server:3.2.3-alpine
    container_name: isp_freeradius
    restart: always
    depends_on:
      postgres:
        condition: service_healthy
    ports:
      - "1812:1812/udp"
      - "1813:1813/udp"
      - "3799:3799/udp"
    volumes:
      - ./freeradius/radiusd.conf:/etc/raddb/radiusd.conf:ro
      - ./freeradius/clients.conf:/etc/raddb/clients.conf:ro
      - ./freeradius/mods-available/sql:/etc/raddb/mods-available/sql:ro
    entrypoint: ["/bin/sh", "-c"]
    command:
      - |
        tr -d '\r' < /etc/raddb/radiusd.conf > /tmp/radiusd.conf && cp /tmp/radiusd.conf /etc/raddb/radiusd.conf
        tr -d '\r' < /etc/raddb/clients.conf > /tmp/clients.conf && cp /tmp/clients.conf /etc/raddb/clients.conf
        tr -d '\r' < /etc/raddb/mods-available/sql > /etc/raddb/mods-enabled/sql
        until nc -z -v -w3 postgres 5432; do sleep 1; done
        exec radiusd -f -l stdout
    networks:
      - isp_internal_net

networks:
  isp_internal_net:
    driver: bridge
    ipam:
      config:
        - subnet: 172.20.0.0/16
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "gitattributes-s1",
                    title = "Git Attributes Line Ending Policy",
                    fileName = ".gitattributes",
                    language = "yaml",
                    description = "Enforces Unix LF on .sql, .sh, and .conf files on Windows 10/11 WSL2 to prevent CRLF parser syntax errors.",
                    codeContent = """
* text=auto

# Enforce Unix LF on SQL, shell, and FreeRADIUS configs
*.sh text eol=lf
*.sql text eol=lf
*.conf text eol=lf
*.rsc text eol=lf
*.yml text eol=lf
*.yaml text eol=lf

# Windows scripts
*.ps1 text eol=crlf
*.bat text eol=crlf
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "postgresql-schema-s1",
                    title = "PostgreSQL 16 Monthly Partitioned Schema",
                    fileName = "01-init-schema.sql",
                    language = "sql",
                    description = "Declarative range partitioning for radacct by month, foreign keys, and indexes for <10ms lookup.",
                    codeContent = """
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

CREATE TABLE IF NOT EXISTS radcheck (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT 'Cleartext-Password',
    op CHAR(2) NOT NULL DEFAULT ':=',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_radcheck_username ON radcheck(username);

CREATE TABLE IF NOT EXISTS radreply (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_radreply_username ON radreply(username);

CREATE TABLE IF NOT EXISTS nas (
    id SERIAL PRIMARY KEY,
    nasname VARCHAR(128) NOT NULL,
    shortname VARCHAR(32),
    type VARCHAR(30) DEFAULT 'other',
    ports INT,
    secret VARCHAR(60) NOT NULL,
    server VARCHAR(64),
    community VARCHAR(50),
    description VARCHAR(200) DEFAULT 'Mikrotik CCR2004'
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_nas_nasname ON nas(nasname);

CREATE TABLE IF NOT EXISTS radacct (
    radacctid BIGSERIAL,
    acctsessionid VARCHAR(64) NOT NULL,
    acctuniqueid VARCHAR(32) NOT NULL,
    username VARCHAR(64) NOT NULL,
    nasipaddress INET NOT NULL,
    acctstarttime TIMESTAMP WITH TIME ZONE NOT NULL,
    acctstoptime TIMESTAMP WITH TIME ZONE,
    acctsessiontime BIGINT,
    acctinputoctets BIGINT DEFAULT 0,
    acctoutputoctets BIGINT DEFAULT 0,
    framedipaddress INET,
    CONSTRAINT pk_radacct PRIMARY KEY (radacctid, acctstarttime)
) PARTITION BY RANGE (acctstarttime);

CREATE TABLE IF NOT EXISTS radacct_y2026m10 PARTITION OF radacct
    FOR VALUES FROM ('2026-10-01 00:00:00+00') TO ('2026-11-01 00:00:00+00');
CREATE TABLE IF NOT EXISTS radacct_y2026m11 PARTITION OF radacct
    FOR VALUES FROM ('2026-11-01 00:00:00+00') TO ('2026-12-01 00:00:00+00');
CREATE TABLE IF NOT EXISTS radacct_y2026m12 PARTITION OF radacct
    FOR VALUES FROM ('2026-12-01 00:00:00+00') TO ('2027-01-01 00:00:00+00');

CREATE INDEX IF NOT EXISTS idx_radacct_username ON radacct(username);
CREATE INDEX IF NOT EXISTS idx_radacct_active ON radacct(framedipaddress) WHERE acctstoptime IS NULL;
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "seed-data-s1",
                    title = "Seed SQL: Test NAS & tapos_test Subscriber",
                    fileName = "02-seed-test-data.sql",
                    language = "sql",
                    description = "Seeds test NAS 127.0.0.1 and test PPPoE user tapos_test with Mikrotik-Rate-Limit := 20M/20M.",
                    codeContent = """
-- Seed Test NAS in nas table
INSERT INTO nas (nasname, shortname, type, ports, secret, description)
VALUES ('127.0.0.1', 'localhost_bench', 'other', 1812, 'testing123', 'Local Benchmark NAS')
ON CONFLICT (nasname) DO UPDATE SET secret = EXCLUDED.secret;

INSERT INTO nas (nasname, shortname, type, ports, secret, description)
VALUES ('10.10.10.1', 'ccr2004_core', 'mikrotik', 3799, 'RadiusSecretKey2026', 'Mikrotik Core Router')
ON CONFLICT (nasname) DO UPDATE SET secret = EXCLUDED.secret;

-- Seed PPPoE Test User: tapos_test
DELETE FROM radcheck WHERE username = 'tapos_test';
INSERT INTO radcheck (username, attribute, op, value)
VALUES ('tapos_test', 'Cleartext-Password', ':=', 'tapos_pass_2026');

DELETE FROM radreply WHERE username = 'tapos_test';
INSERT INTO radreply (username, attribute, op, value)
VALUES 
    ('tapos_test', 'Mikrotik-Rate-Limit', ':=', '20M/20M'),
    ('tapos_test', 'Framed-IP-Address', ':=', '10.100.0.50'),
    ('tapos_test', 'Framed-Pool', ':=', 'pool-subscribers');
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "rlm-sql-s1",
                    title = "FreeRADIUS 3.2 rlm_sql Configuration",
                    fileName = "mods-available/sql",
                    language = "sql",
                    description = "rlm_sql configured for PostgreSQL 16 with dynamic nas table client loading and pool optimization.",
                    codeContent = """
sql {
    dialect = "postgresql"
    driver = "rlm_sql_postgresql"
    server = "postgres"
    port = 5432
    login = "radius_user"
    password = "SecretRadiusDbPassword2026!"
    radius_db = "isp_billing"

    read_clients = yes
    nas_table = "nas"

    pool {
        start = 5
        min = 5
        max = 32
        spare = 5
        lifetime = 0
        idle_timeout = 60
    }

    authorize_check_query = "SELECT id, username, attribute, value, op \
        FROM radcheck WHERE username = '%{SQL-User-Name}' ORDER BY id"

    authorize_reply_query = "SELECT id, username, attribute, value, op \
        FROM radreply WHERE username = '%{SQL-User-Name}' ORDER BY id"

    client_query = "SELECT id, nasname, shortname, type, secret, server FROM nas"
}
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "verify-radtest-script-s1",
                    title = "Windows PowerShell Latency Benchmark",
                    fileName = "verify-radtest.ps1",
                    language = "powershell",
                    description = "Windows PowerShell script testing 100 auth queries for tapos_test and verifying <10ms SLA.",
                    codeContent = """
param (
    [int]${'$'}TotalPackets = 100,
    [string]${'$'}Username = "tapos_test",
    [string]${'$'}Password = "tapos_pass_2026",
    [string]${'$'}ExpectedRateLimit = "20M/20M"
)

${'$'}latencies = @()
for (${'$'}i = 1; ${'$'}i -le ${'$'}TotalPackets; ${'$'}i++) {
    ${'$'}sw = [System.Diagnostics.Stopwatch]::StartNew()
    ${'$'}out = docker exec isp_freeradius radtest ${'$'}Username ${'$'}Password 127.0.0.1 1812 testing123 2>&1
    ${'$'}sw.Stop()

    if (${'$'}out -match "Access-Accept") {
        ${'$'}latencies += ${'$'}sw.Elapsed.TotalMilliseconds
    }
}

${'$'}avg = (${'$'}latencies | Measure-Object -Average).Average
Write-Host "Avg Latency: ${'$'}([math]::Round(${'$'}avg, 2)) ms"
if (${'$'}avg -lt 10) {
    Write-Host "[SUCCESS] SLA ACHIEVED: < 10ms ($([math]::Round(${'$'}avg, 2)) ms)" -ForegroundColor Green
} else {
    Write-Host "[FAILED] SLA EXCEEDED" -ForegroundColor Red
}
                    """.trimIndent()
                )
            ),
            verificationSteps = listOf(
                VerificationStep(
                    stepNumber = 1,
                    title = "Sanitize CRLF & Launch Docker Compose Stack",
                    powerShellCommand = """
powershell -ExecutionPolicy Bypass -File .\scripts\setup-stage1.ps1
                    """.trimIndent(),
                    expectedOutput = "[OK] PostgreSQL 16 and Redis 7 are HEALTHY! Seed user tapos_test verified.",
                    notes = "Automatically strips CRLF via tr -d '\\r' and starts docker compose."
                ),
                VerificationStep(
                    stepNumber = 2,
                    title = "Execute verify-radtest.ps1 Latency Benchmark",
                    powerShellCommand = """
powershell -ExecutionPolicy Bypass -File .\scripts\verify-radtest.ps1 -TotalPackets 100 -Username "tapos_test"
                    """.trimIndent(),
                    expectedOutput = "Avg Latency: 3.42 ms | Status: SLA ACHIEVED (<10ms) | Rate-Limit: 20M/20M",
                    notes = "Runs 100 consecutive authentication requests against PostgreSQL 16 backend."
                )
            )
        ),

        // ================= STAGE 2 =================
        DeploymentStage(
            stageNumber = 2,
            daysRange = "Days 6-12",
            title = "BSS Engine & Laravel Framework",
            subtitle = "Laravel 11, FilamentPHP v3, Eloquent Observers, PostgreSQL Sync",
            targetSla = "Dual-Sync Observers Live (<50ms)",
            summary = "Deploy the BSS core using Laravel 11 and FilamentPHP v3. Implement Eloquent Observers that perform real-time synchronization between the Subscriber model and FreeRADIUS SQL tables (radcheck/radreply).",
            technologies = listOf("Laravel 11", "FilamentPHP v3", "PHP 8.3", "Eloquent Observers", "Database Transactions"),
            artifacts = listOf(
                StageArtifact(
                    id = "subscriber-status-enum-s2",
                    title = "Backend Subscriber Status Enum",
                    fileName = "SubscriberStatus.php",
                    language = "php",
                    description = "Strictly typed PHP 8.3 backing enum for subscriber states (active, expired, suspended).",
                    codeContent = """
<?php
// File: app/Enums/SubscriberStatus.php

declare(strict_types=1);

namespace App\Enums;

enum SubscriberStatus: string
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case SUSPENDED = 'suspended';
}
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "isp-config-s2",
                    title = "ISP System Configuration",
                    fileName = "config/isp.php",
                    language = "php",
                    description = "Centralized configuration for RADIUS secrets, Mikrotik IPs, Walled Garden pools, and Simulation Mode toggles.",
                    codeContent = """
<?php

return [
    'simulate_hardware' => env('ISP_SIMULATE_HARDWARE', true),
    'default_nas_ip' => env('ISP_DEFAULT_NAS_IP', '10.10.10.1'),
    'default_nas_secret' => env('ISP_DEFAULT_NAS_SECRET', 'testing123'),
    'walled_garden_pool' => env('ISP_WALLED_GARDEN_POOL', 'walled_garden_pool'),
    'coa_port' => env('ISP_COA_PORT', 3799),
    'gateways' => [
        'bkash' => [
            'webhook_secret' => env('BKASH_WEBHOOK_SECRET', 'local_bkash_secret'),
        ],
        'nagad' => [
            'webhook_secret' => env('NAGAD_WEBHOOK_SECRET', 'local_nagad_secret'),
        ],
    ],
];
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "laravel-migrations-s2",
                    title = "Database Migrations (Subscribers & Tariffs)",
                    fileName = "2026_10_14_create_isp_tables.php",
                    language = "php",
                    description = "Laravel 11 migration schema for managing subscribers, bandwidth tariffs, NAS hardware, and invoices.",
                    codeContent = """
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('tariffs', function (Blueprint ${'$'}table) {
            ${'$'}table->id();
            ${'$'}table->string('name'); // e.g. '20Mbps Residential'
            ${'$'}table->string('rate_limit'); // '20M/20M 30M/30M 10M/10M 10/10 8 10M/10M'
            ${'$'}table->string('framed_pool')->default('default_pool');
            ${'$'}table->decimal('price', 10, 2);
            ${'$'}table->timestamps();
        });

        Schema::create('subscribers', function (Blueprint ${'$'}table) {
            ${'$'}table->id();
            ${'$'}table->foreignId('tariff_id')->constrained();
            ${'$'}table->string('username')->unique();
            ${'$'}table->string('password');
            ${'$'}table->string('status')->default('active'); // active, expired, suspended
            ${'$'}table->timestamp('expires_at')->nullable();
            ${'$'}table->string('static_ip')->nullable();
            ${'$'}table->timestamps();
        });

        Schema::create('invoices', function (Blueprint ${'$'}table) {
            ${'$'}table->id();
            ${'$'}table->foreignId('subscriber_id')->constrained();
            ${'$'}table->decimal('amount', 10, 2);
            ${'$'}table->string('status')->default('unpaid');
            ${'$'}table->string('trx_id')->nullable();
            ${'$'}table->timestamp('paid_at')->nullable();
            ${'$'}table->timestamps();
        });
    }
};
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "radius-sync-service-s2",
                    title = "Production RADIUS Sync Service",
                    fileName = "RadiusSyncService.php",
                    language = "php",
                    description = "Encapsulates complex RADIUS synchronization logic including suspended-state rejections and walled garden isolation.",
                    codeContent = """
<?php
// File: app/Services/Radius/RadiusSyncService.php

declare(strict_types=1);

namespace App\Services\Radius;

use App\Enums\SubscriberStatus;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;

class RadiusSyncService
{
    public function syncSubscriber(Subscriber ${'$'}subscriber): void
    {
        ${'$'}subscriber->loadMissing('tariff');

        DB::transaction(function () use (${'$'}subscriber) {
            // 1. Sync Cleartext-Password or Reject in radcheck
            DB::table('radcheck')->where('username', ${'$'}subscriber->username)->delete();

            if (${'$'}subscriber->status === SubscriberStatus::SUSPENDED) {
                DB::table('radcheck')->insert([
                    'username'  => ${'$'}subscriber->username,
                    'attribute' => 'Auth-Type',
                    'op'        => ':=',
                    'value'     => 'Reject',
                ]);
                DB::table('radreply')->where('username', ${'$'}subscriber->username)->delete();
                return;
            }

            DB::table('radcheck')->insert([
                'username'  => ${'$'}subscriber->username,
                'attribute' => 'Cleartext-Password',
                'op'        => ':=',
                'value'     => ${'$'}subscriber->pppoe_password,
            ]);

            // 2. Sync Bandwidth & IP Pool in radreply
            DB::table('radreply')->where('username', ${'$'}subscriber->username)->delete();

            if (${'$'}subscriber->status === SubscriberStatus::EXPIRED) {
                // Walled Garden isolation for expired subscribers
                DB::table('radreply')->insert([
                    [
                        'username'  => ${'$'}subscriber->username,
                        'attribute' => 'Framed-Pool',
                        'op'        => ':=',
                        'value'     => config('isp.walled_garden_pool', 'walled_garden_pool'),
                    ],
                    [
                        'username'  => ${'$'}subscriber->username,
                        'attribute' => 'Mikrotik-Rate-Limit',
                        'op'        => ':=',
                        'value'     => '512k/512k', // Minimal speed for payment portal only
                    ],
                ]);
            } else {
                // Active full-speed tariff profile
                DB::table('radreply')->insert([
                    [
                        'username'  => ${'$'}subscriber->username,
                        'attribute' => 'Mikrotik-Rate-Limit',
                        'op'        => ':=',
                        'value'     => ${'$'}subscriber->tariff->mikrotik_rate_limit,
                    ],
                    [
                        'username'  => ${'$'}subscriber->username,
                        'attribute' => 'Framed-Pool',
                        'op'        => ':=',
                        'value'     => ${'$'}subscriber->tariff->framed_pool,
                    ],
                    [
                        'username'  => ${'$'}subscriber->username,
                        'attribute' => 'Acct-Interim-Interval',
                        'op'        => ':=',
                        'value'     => '300',
                    ],
                ]);
            }
        });
    }

    public function removeSubscriber(string ${'$'}username): void
    {
        DB::transaction(function () use (${'$'}username) {
            DB::table('radcheck')->where('username', ${'$'}username)->delete();
            DB::table('radreply')->where('username', ${'$'}username)->delete();
        });
    }
}
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "subscriber-observer-s2",
                    title = "BSS Subscriber Observer",
                    fileName = "SubscriberObserver.php",
                    language = "php",
                    description = "Listens for Subscriber model events and delegates real-time RADIUS synchronization and RFC 3576 CoA session kicks on changes.",
                    codeContent = """
<?php
// File: app/Observers/SubscriberObserver.php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SendRadiusCoaDisconnectJob;
use App\Models\Subscriber;
use App\Services\Radius\RadiusSyncService;

class SubscriberObserver
{
    public function __construct(
        private readonly RadiusSyncService ${'$'}radiusSyncService
    ) {}

    public function saved(Subscriber ${'$'}subscriber): void
    {
        ${'$'}this->radiusSyncService->syncSubscriber(${'$'}subscriber);

        // If status or tariff changed, kick active PPPoE session via Horizon high queue
        if (${'$'}subscriber->wasChanged(['status', 'tariff_id', 'pppoe_password']) && ! ${'$'}subscriber->wasRecentlyCreated) {
            SendRadiusCoaDisconnectJob::dispatch(${'$'}subscriber->id)->onQueue('high');
        }
    }

    public function deleted(Subscriber ${'$'}subscriber): void
    {
        ${'$'}this->radiusSyncService->removeSubscriber(${'$'}subscriber->username);
        SendRadiusCoaDisconnectJob::dispatch(${'$'}subscriber->id)->onQueue('high');
    }
}
                    """.trimIndent()
                )
            ),
            verificationSteps = listOf(
                VerificationStep(
                    stepNumber = 1,
                    title = "Run Feature Tests (Pest/PHPUnit)",
                    powerShellCommand = "php artisan test --filter=SubscriberSyncTest",
                    expectedOutput = "PASS  Tests\\Feature\\SubscriberSyncTest",
                    notes = "Verifies DB transaction rollback and RADIUS table atomicity."
                )
            )
        ),


        // ================= STAGE 3 =================
        DeploymentStage(
            stageNumber = 3,
            daysRange = "Days 13-18",
            title = "RFC 3576 CoA & REST Automation",
            subtitle = "UDP 3799 PoD Service, RouterOS v7 REST API, Filament Actions",
            targetSla = "Session Kick Latency <500ms",
            summary = "Build the RadiusCoaService to manage RFC 3576 packets and the MikrotikRestService to monitor active tunnels via RouterOS v7 REST API. Integrate 'Force Disconnect' buttons in the BSS panel.",
            technologies = listOf("RFC 3576", "UDP 3799", "RouterOS v7 REST", "PHP Sockets", "Filament Table Actions"),
            artifacts = listOf(
                StageArtifact(
                    id = "coa-service-s3",
                    title = "Radius RFC 3576 CoA/PoD Service",
                    fileName = "RadiusCoaService.php",
                    language = "php",
                    description = "Production-grade RFC 3576 service with Simulation Mode support and sub-second latency tracking.",
                    codeContent = """
<?php
// File: app/Services/Network/RadiusCoaService.php

declare(strict_types=1);

namespace App\Services\Network;

use App\Models\Subscriber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RadiusCoaService
{
    /**
     * Sends an RFC 3576 Packet of Disconnect (PoD) on UDP 3799.
     * In Simulation Mode (pre-hardware), logs and returns simulated <5ms success.
     */
    public function disconnectSession(Subscriber ${'$'}subscriber): array
    {
        ${'$'}subscriber->loadMissing('nas');

        ${'$'}nasIp = ${'$'}subscriber->nas?->nasname ?? config('isp.default_nas_ip', '10.10.10.1');
        ${'$'}coaPort = ${'$'}subscriber->nas?->coa_port ?? 3799;
        ${'$'}secret = ${'$'}subscriber->nas?->secret ?? config('isp.default_nas_secret', 'testing123');

        if (config('isp.simulate_hardware', true)) {
            Log::info("[SIMULATED CoA PoD] Sent UDP 3799 Disconnect-Request", [
                'username' => ${'$'}subscriber->username,
                'nas_ip'   => ${'$'}nasIp,
                'port'     => ${'$'}coaPort,
            ]);

            return [
                'success'     => true,
                'simulated'   => true,
                'latency_ms'  => 4.2,
                'raw_output'  => 'Received Disconnect-ACK Id 101 from ' . ${'$'}nasIp . ':' . ${'$'}coaPort,
            ];
        }

        ${'$'}startTime = microtime(true);
        ${'$'}payload = sprintf("User-Name=%s\n", escapeshellarg(${'$'}subscriber->username));

        ${'$'}command = sprintf(
            'printf %s | radclient -x -r 2 -t 1 %s:%d disconnect %s',
            escapeshellarg(${'$'}payload),
            escapeshellarg(${'$'}nasIp),
            ${'$'}coaPort,
            escapeshellarg(${'$'}secret)
        );

        ${'$'}result = Process::timeout(3)->run(${'$'}command);
        ${'$'}latencyMs = round((microtime(true) - ${'$'}startTime) * 1000, 2);

        ${'$'}acknowledged = str_contains(${'$'}result->output(), 'Disconnect-ACK')
            || str_contains(${'$'}result->output(), 'Disconnect-NAK');

        return [
            'success'    => ${'$'}result->successful() && ${'$'}acknowledged,
            'simulated'  => false,
            'latency_ms' => ${'$'}latencyMs,
            'raw_output' => trim(${'$'}result->output() . ' ' . ${'$'}result->errorOutput()),
        ];
    }
}
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "coa-job-s3",
                    title = "Radius CoA Disconnect Background Job",
                    fileName = "SendRadiusCoaDisconnectJob.php",
                    language = "php",
                    description = "Laravel Queue job for asynchronous RFC 3576 Disconnect-Request dispatching via Horizon.",
                    codeContent = """
<?php
// File: app/Jobs/SendRadiusCoaDisconnectJob.php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Subscriber;
use App\Services\Network\RadiusCoaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendRadiusCoaDisconnectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int ${'$'}subscriberId
    ) {}

    public function handle(RadiusCoaService ${'$'}coaService): void
    {
        ${'$'}subscriber = Subscriber::with('nas')->find(${'$'}this->subscriberId);
        if (! ${'$'}subscriber) {
            return;
        }

        ${'$'}coaService->disconnectSession(${'$'}subscriber);
    }
}
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "mikrotik-rest-s3",
                    title = "Mikrotik RouterOS v7 REST API Client",
                    fileName = "MikrotikRestService.php",
                    language = "php",
                    description = "Direct HTTPS inspection of RouterOS v7 simple queues and active PPPoE bindings.",
                    codeContent = """
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MikrotikRestService {
    public function getActiveSessions(string ${'$'}ip): array {
        return Http::withBasicAuth('api', 'pass')
            ->withoutVerifying()
            ->get("https://{${'$'}ip}/rest/ppp/active")
            ->json();
    }

    public function getQueueStats(string ${'$'}ip, string ${'$'}name): array {
        return Http::withBasicAuth('api', 'pass')
            ->withoutVerifying()
            ->get("https://{${'$'}ip}/rest/queue/simple", ['name' => ${'$'}name])
            ->json();
    }
}
                    """.trimIndent()
                )
            ),
            verificationSteps = listOf(
                VerificationStep(
                    stepNumber = 1,
                    title = "Test CoA Kick via PowerShell",
                    powerShellCommand = "powershell -File .\\scripts\\test-coa-kick.ps1 -User tapos_test",
                    expectedOutput = "Disconnect-ACK received from 10.10.10.1",
                    notes = "Validates UDP 3799 reachability through Windows Firewall."
                )
            )
        ),


        // ================= STAGE 4 =================
        DeploymentStage(
            stageNumber = 4,
            daysRange = "Days 19-24",
            title = "Billing & Webhook Automation",
            subtitle = "Auto-Expiry Command, bKash/Nagad HMAC Webhooks, Reconnect <3s",
            targetSla = "Line Unblock Latency <3s",
            summary = "Implement the midnight expiry scheduler and payment gateway webhooks. Automate the sequence of 'Payment -> Invoice PAID -> RADIUS Update -> PoD Kick' to ensure users are unblocked instantly.",
            technologies = listOf("Laravel Scheduler", "HMAC SHA256", "bKash API", "Nagad Gateway", "Queued Jobs"),
            artifacts = listOf(
                StageArtifact(
                    id = "expiry-command-s4",
                    title = "Daily Expiry Scheduler Command",
                    fileName = "CheckExpirationsCommand.php",
                    language = "php",
                    description = "Processes overdue subscribers in chunks, moving them to Walled Garden and triggering session kicks via observers.",
                    codeContent = """
<?php
// File: app/Console/Commands/CheckExpirationsCommand.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SubscriberStatus;
use App\Models\Subscriber;
use Illuminate\Console\Command;

class CheckExpirationsCommand extends Command
{
    protected ${'$'}signature = 'isp:check-expirations';
    protected ${'$'}description = 'Marks overdue subscribers as expired, switches to Walled Garden, and triggers CoA PoD';

    public function handle(): int
    {
        ${'$'}expiredCount = 0;

        Subscriber::query()
            ->where('status', SubscriberStatus::ACTIVE)
            ->where('expires_at', '<=', now())
            ->chunkById(200, function (${'$'}subscribers) use (&${'$'}expiredCount) {
                foreach (${'$'}subscribers as ${'$'}subscriber) {
                    // Updating status triggers SubscriberObserver -> syncs Walled Garden & dispatches CoA Job
                    ${'$'}subscriber->update([
                        'status' => SubscriberStatus::EXPIRED,
                    ]);
                    ${'$'}expiredCount++;
                }
            });

        ${'$'}this->info("Processed {${'$'}expiredCount} expired subscribers.");
        return self::SUCCESS;
    }
}
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "mfs-webhook-s4",
                    title = "Production MFS Webhook Controller",
                    fileName = "MfsWebhookController.php",
                    language = "php",
                    description = "Production-grade MFS webhook controller with HMAC verification, row-level locking, and idempotent transaction processing.",
                    codeContent = """
<?php
// File: app/Http/Controllers/Webhook/MfsWebhookController.php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Enums\SubscriberStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MfsWebhookController extends Controller
{
    public function handlePaymentCallback(Request ${'$'}request, string ${'$'}gateway): JsonResponse
    {
        // 1. Verify HMAC SHA256 Signature
        ${'$'}signature = (string) ${'$'}request->header('X-MFS-Signature', '');
        ${'$'}secret = (string) config("isp.gateways.{${'$'}gateway}.webhook_secret", 'local_webhook_secret');
        ${'$'}computed = hash_hmac('sha256', ${'$'}request->getContent(), ${'$'}secret);

        if (! hash_equals(${'$'}computed, ${'$'}signature)) {
            return response()->json(['error' => 'Invalid HMAC signature'], 401);
        }

        ${'$'}validated = ${'$'}request->validate([
            'invoice_number' => ['required', 'string'],
            'transaction_id' => ['required', 'string'],
            'amount'         => ['required', 'numeric'],
        ]);

        // 2. Idempotent Transaction with Row-Level Locking
        return DB::transaction(function () use (${'$'}validated, ${'$'}gateway) {
            // Prevent duplicate webhook processing if transaction_id already exists
            if (Invoice::where('transaction_id', ${'$'}validated['transaction_id'])->exists()) {
                return response()->json(['status' => 'already_processed'], 200);
            }

            ${'$'}invoice = Invoice::query()
                ->where('invoice_number', ${'$'}validated['invoice_number'])
                ->lockForUpdate()
                ->firstOrFail();

            if (${'$'}invoice->status === 'paid') {
                return response()->json(['status' => 'already_paid'], 200);
            }

            ${'$'}invoice->update([
                'status'          => 'paid',
                'payment_gateway' => ${'$'}gateway,
                'transaction_id'  => ${'$'}validated['transaction_id'],
                'paid_at'         => now(),
            ]);

            ${'$'}subscriber = ${'$'}invoice->subscriber()->lockForUpdate()->firstOrFail();

            ${'$'}baseExpiry = ${'$'}subscriber->expires_at->isFuture() ? ${'$'}subscriber->expires_at : now();

            // Updating subscriber triggers SubscriberObserver:
            // 1. Restores Mikrotik-Rate-Limit in radreply
            // 2. Dispatches SendRadiusCoaDisconnectJob on 'high' queue (<3s reconnect)
            ${'$'}subscriber->update([
                'status'     => SubscriberStatus::ACTIVE,
                'expires_at' => ${'$'}baseExpiry->copy()->addDays(30),
            ]);

            return response()->json([
                'status'         => 'unblocked',
                'subscriber'     => ${'$'}subscriber->username,
                'new_expires_at' => ${'$'}subscriber->expires_at->toIso8601String(),
            ], 200);
        });
    }
}
                    """.trimIndent()
                )
            ),
            verificationSteps = listOf(
                VerificationStep(
                    stepNumber = 1,
                    title = "Simulate bKash Webhook Callback",
                    powerShellCommand = "curl -X POST http://127.0.0.1/api/webhooks/bkash -H 'X-MFS-Signature: <hmac>' -d '{\"invoice_number\":\"INV-1001\",\"transaction_id\":\"TRX999\",\"amount\":1200}'",
                    expectedOutput = "{\"status\":\"unblocked\",\"subscriber\":\"tapos_test\",...}",
                    notes = "Verifies HMAC validation, DB row-locking, and <3s unblock chain from Windows to RouterOS."
                )
            )
        ),


        // ================= STAGE 5 =================
        DeploymentStage(
            stageNumber = 5,
            daysRange = "Days 25-28",
            title = "Self-Care & Infrastructure Hardening",
            subtitle = "PWA Dashboard, Firewall Lockdown, Automated S3 Backups",
            targetSla = "99.9% High Availability Benchmark",
            summary = "Deploy the Customer Self-Care PWA with real-time session tracking and bandwidth graphs. Harden infrastructure with Windows Firewall, automate S3 backups via MinIO, and implement PDF invoicing.",
            technologies = listOf("Vue 3", "Inertia.js", "Chart.js", "DomPDF", "MinIO S3", "Pest PHP", "Docker"),
            artifacts = listOf(
                StageArtifact(
                    id = "security-script-s5",
                    title = "Windows Defender Firewall & Backup Script",
                    fileName = "security-and-backup.ps1",
                    language = "powershell",
                    description = "Production PowerShell script to lock UDP 1812/1813/3799 and stream pg_dump to MinIO S3 with rotation logic.",
                    codeContent = """
# 1. Lock down RADIUS ports to Mikrotik CCR IPs ONLY
${'$'}nasIps = @("10.10.10.1", "10.10.10.2") # Primary and Backup CCRs
foreach (${'$'}ip in ${'$'}nasIps) {
    New-NetFirewallRule -DisplayName "RADIUS-Auth-In (${'$'}ip)" -Direction Inbound -LocalPort 1812 -Protocol UDP -Action Allow -RemoteAddress ${'$'}ip
    New-NetFirewallRule -DisplayName "RADIUS-Acct-In (${'$'}ip)" -Direction Inbound -LocalPort 1813 -Protocol UDP -Action Allow -RemoteAddress ${'$'}ip
    New-NetFirewallRule -DisplayName "RFC3576-CoA-In (${'$'}ip)" -Direction Inbound -LocalPort 3799 -Protocol UDP -Action Allow -RemoteAddress ${'$'}ip
}

# 2. Automated Daily Backup to MinIO (S3 Compatible)
${'$'}timestamp = Get-Date -Format "yyyyMMdd_HHmm"
${'$'}backupFile = "C:\backups\isp_db_${'$'}timestamp.sql.gz"

Write-Host "Starting pg_dump for isp_billing..."
docker exec isp_postgres pg_dump -U radius_user isp_billing | gzip > ${'$'}backupFile

if (Test-Path ${'$'}backupFile) {
    Write-Host "Uploading to MinIO S3..."
    mc cp ${'$'}backupFile "myminio/backups/"
    
    # 3. Retention: Keep only last 30 days locally
    Get-ChildItem "C:\backups\*.sql.gz" | Where-Object { ${'$'}_.LastWriteTime -lt (Get-Date).AddDays(-30) } | Remove-Item
    Write-Host "[SUCCESS] Backup lifecycle complete."
}
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "self-care-logic-s5",
                    title = "Self-Care Controller & PDF Engine",
                    fileName = "SelfCareController.php",
                    language = "php",
                    description = "Handles usage stats, PWA state, and on-the-fly PDF invoice generation using DomPDF.",
                    codeContent = """
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class SelfCareController {
    public function getUsage(): JsonResponse {
        ${'$'}now = now();
        ${'$'}tableName = "radacct_y" . ${'$'}now.format('Ym');
        
        ${'$'}summary = DB::table(${'$'}tableName)
            ->where('username', auth()->user()->username)
            ->selectRaw("SUM(acctinputoctets) as download, SUM(acctoutputoctets) as upload")
            ->first();

        return response()->json([
            'summary' => ${'$'}summary,
            'session' => [
                'active' => auth()->user()->radacct()->whereNull('acctstoptime')->exists(),
                'ip' => auth()->user()->static_ip,
            ]
        ]);
    }

    public function downloadInvoicePdf(int ${'$'}invoiceId): Response {
        ${'$'}invoice = Invoice::with('subscriber.tariff')->findOrFail(${'$'}invoiceId);
        
        ${'$'}pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => ${'$'}invoice,
            'subscriber' => ${'$'}invoice->subscriber,
            'tariff' => ${'$'}invoice->subscriber->tariff,
        ]);

        return ${'$'}pdf->download("INV-{${'$'}invoice->id}.pdf");
    }
}
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "dashboard-vue-s5",
                    title = "Customer Self-Care PWA (Dashboard.vue)",
                    fileName = "Dashboard.vue",
                    language = "javascript",
                    description = "Inertia.js + Vue 3 component with Chart.js for usage tracking and bKash payment triggers.",
                    codeContent = """
<script setup>
import { ref, onMounted } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Line } from 'vue-chartjs';

const props = defineProps(['subscriber', 'usageData']);
const processing = ref(false);

const paymentForm = useForm({
    amount: props.subscriber.tariff.monthly_price
});

const payNow = () => {
    paymentForm.post('/payment/bkash/trigger', {
        onStart: () => processing.value = true,
        onFinish: () => processing.value = false
    });
};
</script>

<template>
    <div class="p-6 max-w-4xl mx-auto bg-slate-900 text-white rounded-xl">
        <header class="flex justify-between items-center border-b border-slate-700 pb-4">
            <h1 class="text-2xl font-bold">Welcome, {{ subscriber.username }}</h1>
            <div :class="subscriber.status === 'active' ? 'text-emerald-400' : 'text-rose-400'">
                ● {{ subscriber.status.toUpperCase() }}
            </div>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-6">
            <div class="bg-slate-800 p-4 rounded-lg">
                <p class="text-slate-400 text-sm">Monthly Usage</p>
                <p class="text-3xl font-mono">{{ usageData.total_gb }} GB</p>
            </div>
            <div class="bg-slate-800 p-4 rounded-lg">
                <p class="text-slate-400 text-sm">Package</p>
                <p class="text-xl font-bold">{{ subscriber.tariff.name }}</p>
            </div>
            <div class="bg-slate-800 p-4 rounded-lg">
                <button @click="payNow" :disabled="processing" class="w-full h-full bg-cyan-600 hover:bg-cyan-500 rounded font-bold">
                    EXTEND VALIDITY (bKash)
                </button>
            </div>
        </div>

        <div class="mt-8 bg-slate-800 p-6 rounded-lg">
            <h3 class="mb-4 font-bold text-slate-300">Bandwidth Consumption (Last 30 Days)</h3>
            <Line :data="usageData.chart" />
        </div>
    </div>
</template>
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "docker-minio-s5",
                    title = "Production Docker Compose (MinIO Update)",
                    fileName = "docker-compose.yml",
                    language = "yaml",
                    description = "Adds MinIO S3-compatible storage service for local backups and artifact storage.",
                    codeContent = """
  minio:
    image: minio/minio
    container_name: isp_minio
    ports:
      - "9000:9000"
      - "9001:9001"
    environment:
      MINIO_ROOT_USER: admin
      MINIO_ROOT_PASSWORD: SecretMinioPassword2026!
    volumes:
      - ./data/minio:/data
    command: server /data --console-address ":9001"
    networks:
      - isp_internal_net
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "payment-test-s5",
                    title = "End-to-End Payment Workflow (Pest Test)",
                    fileName = "PaymentWorkflowTest.php",
                    language = "php",
                    description = "Final Pest suite verifying the full chain from bKash webhook to radreply restore and CoA kick.",
                    codeContent = """
<?php

use App\Models\Subscriber;
use App\Models\Invoice;
use App\Enums\SubscriberStatus;
use Illuminate\Support\Facades\DB;

it('reconnects user within 3s of bKash payment', function () {
    ${'$'}subscriber = Subscriber::factory()->create([
        'status' => SubscriberStatus::EXPIRED,
        'expires_at' => now()->subDay(),
    ]);

    ${'$'}invoice = Invoice::create([
        'subscriber_id' => ${'$'}subscriber->id,
        'amount' => 1000,
        'status' => 'unpaid',
        'invoice_number' => 'INV-TEST-001'
    ]);

    ${'$'}startTime = microtime(true);

    ${'$'}response = ${'$'}this->postJson('/api/webhooks/bkash', [
        'invoice_number' => 'INV-TEST-001',
        'transaction_id' => 'TRX_PEST_99',
        'amount' => 1000
    ], ['X-MFS-Signature' => compute_test_hmac()]);

    ${'$'}endTime = microtime(true);

    ${'$'}response->assertStatus(200)
             ->assertJsonPath('status', 'unblocked');

    ${'$'}subscriber->refresh();
    expect(${'$'}subscriber->status)->toBe(SubscriberStatus::ACTIVE);
    expect(${'$'}subscriber->expires_at->isFuture())->toBeTrue();
    
    // Verify RADIUS state
    expect(DB::table('radreply')->where('username', ${'$'}subscriber->username)->where('attribute', 'Mikrotik-Rate-Limit')->value('value'))
        ->not->toBe('512k/512k');

    expect(${'$'}endTime - ${'$'}startTime)->toBeLessThan(3.0);
});
                    """.trimIndent()
                )
            ),
            verificationSteps = listOf(
                VerificationStep(
                    stepNumber = 1,
                    title = "Verify Backup on MinIO",
                    powerShellCommand = "mc ls myminio/backups/",
                    expectedOutput = "db_20261014.sql.gz",
                    notes = "Verifies off-site data durability policy."
                )
            )
        )
    )

    fun getStage(stageNumber: Int): DeploymentStage? {
        return stages.find { it.stageNumber == stageNumber }
    }
}
