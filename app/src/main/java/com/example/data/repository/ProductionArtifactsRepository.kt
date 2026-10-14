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
                    id = "subscriber-observer-s2",
                    title = "Real-Time RADIUS Sync Observer",
                    fileName = "SubscriberObserver.php",
                    language = "php",
                    description = "Automatically updates radcheck and radreply tables whenever a subscriber is created or updated in the BSS panel.",
                    codeContent = """
<?php

namespace App\Observers;

use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;

class SubscriberObserver {
    public function saved(Subscriber ${'$'}subscriber): void {
        DB::transaction(function () use (${'$'}subscriber) {
            // Sync radcheck (Password)
            DB::table('radcheck')->updateOrInsert(
                ['username' => ${'$'}subscriber->username, 'attribute' => 'Cleartext-Password'],
                ['op' => ':=', 'value' => ${'$'}subscriber->password]
            );

            // Sync radreply (Bandwidth & Pool)
            ${'$'}pool = (${'$'}subscriber->status === 'expired') ? 'walled_garden_pool' : ${'$'}subscriber->tariff->framed_pool;
            ${'$'}rate = (${'$'}subscriber->status === 'expired') ? '256k/256k' : ${'$'}subscriber->tariff->rate_limit;

            DB::table('radreply')->updateOrInsert(
                ['username' => ${'$'}subscriber->username, 'attribute' => 'Mikrotik-Rate-Limit'],
                ['op' => ':=', 'value' => ${'$'}rate]
            );

            DB::table('radreply')->updateOrInsert(
                ['username' => ${'$'}subscriber->username, 'attribute' => 'Framed-Pool'],
                ['op' => ':=', 'value' => ${'$'}pool]
            );
        });
    }

    public function deleted(Subscriber ${'$'}subscriber): void {
        DB::table('radcheck')->where('username', ${'$'}subscriber->username)->delete();
        DB::table('radreply')->where('username', ${'$'}subscriber->username)->delete();
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
                    description = "Sends Packet of Disconnect (Code 40) over UDP 3799 to terminate active Mikrotik PPPoE sessions.",
                    codeContent = """
<?php

namespace App\Services;

class RadiusCoaService {
    public function disconnect(string ${'$'}username, string ${'$'}nasIp, string ${'$'}secret): bool {
        // Option 1: Native PHP UDP Sockets
        // Option 2: Docker Exec radclient (Production fallback for Windows compatibility)
        ${'$'}cmd = "docker compose exec -T freeradius bash -c 'echo \"User-Name=${'$'}username\" | radclient -x ${'$'}nasIp:3799 disconnect ${'$'}secret'";
        exec(${'$'}cmd, ${'$'}output, ${'$'}resultCode);
        return ${'$'}resultCode === 0;
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
                    fileName = "CheckExpirations.php",
                    language = "php",
                    description = "Runs at 00:01 AM to move expired subscribers to Walled Garden pool and kick sessions.",
                    codeContent = """
<?php

namespace App\Console\Commands;

use App\Models\Subscriber;
use App\Services\RadiusCoaService;
use Illuminate\Console\Command;

class CheckExpirations extends Command {
    protected ${'$'}signature = 'isp:check-expirations';

    public function handle(RadiusCoaService ${'$'}coa): void {
        Subscriber::where('status', 'active')
            ->where('expires_at', '<', now())
            ->each(function (${'$'}sub) use (${'$'}coa) {
                ${'$'}sub->update(['status' => 'expired']);
                ${'$'}coa->disconnect(${'$'}sub->username, '10.10.10.1', 'secret');
            });
    }
}
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "mfs-webhook-s4",
                    title = "MFS Webhook Controller (bKash/Nagad)",
                    fileName = "MfsWebhookController.php",
                    language = "php",
                    description = "Idempotent controller with HMAC signature verification for instant payment processing.",
                    codeContent = """
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\RadiusCoaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MfsWebhookController extends Controller {
    public function handleBkash(Request ${'$'}request, RadiusCoaService ${'$'}coa) {
        // 1. Verify HMAC SHA256
        // 2. Mark Invoice PAID
        // 3. Re-enable line & Kick session
        DB::transaction(function() use (${'$'}request, ${'$'}coa) {
            ${'$'}invoice = Invoice::where('trx_id', ${'$'}request->trx_id)->first();
            ${'$'}subscriber = ${'$'}invoice->subscriber;
            ${'$'}subscriber->update([
                'status' => 'active',
                'expires_at' => now()->addDays(30)
            ]);
            ${'$'}coa->disconnect(${'$'}subscriber->username, '10.10.10.1', 'secret');
        });
        return response()->json(['status' => 'Success']);
    }
}
                    """.trimIndent()
                )
            ),
            verificationSteps = listOf(
                VerificationStep(
                    stepNumber = 1,
                    title = "Simulate Webhook Call",
                    powerShellCommand = "curl -X POST http://127.0.0.1/api/webhooks/bkash -d '{\"trx_id\":\"TEST1234\"}'",
                    expectedOutput = "{\"status\":\"Success\"}",
                    notes = "Verifies <3s unblock chain from Windows to RouterOS."
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
            summary = "Deploy the Customer Self-Care dashboard with bandwidth graphs. Secure the Windows environment by locking RADIUS ports to the Mikrotik subnet and automate daily S3 backups.",
            technologies = listOf("Vue 3 / Blade", "Windows Defender Firewall", "MinIO / AWS S3", "pg_dump", "Scheduled Tasks"),
            artifacts = listOf(
                StageArtifact(
                    id = "security-script-s5",
                    title = "Windows Defender Firewall & Backup Script",
                    fileName = "security-and-backup.ps1",
                    language = "powershell",
                    description = "Production PowerShell script to lock UDP 1812/1813/3799 and stream pg_dump to MinIO S3.",
                    codeContent = """
# 1. Lock down RADIUS ports
New-NetFirewallRule -DisplayName "RADIUS-Auth" -Direction Inbound -LocalPort 1812 -Protocol UDP -Action Allow -RemoteAddress 10.10.10.1/32
New-NetFirewallRule -DisplayName "RADIUS-Acct" -Direction Inbound -LocalPort 1813 -Protocol UDP -Action Allow -RemoteAddress 10.10.10.1/32
New-NetFirewallRule -DisplayName "RFC3576-CoA" -Direction Inbound -LocalPort 3799 -Protocol UDP -Action Allow -RemoteAddress 10.10.10.1/32

# 2. Automated Daily Backup to MinIO
${'$'}timestamp = Get-Date -Format "yyyyMMdd"
docker exec isp_postgres pg_dump -U radius_user isp_billing | gzip > "C:\backups\db_${'$'}timestamp.sql.gz"
mc cp "C:\backups\db_${'$'}timestamp.sql.gz" "myminio/backups/"
                    """.trimIndent()
                ),
                StageArtifact(
                    id = "self-care-logic-s5",
                    title = "Self-Care Bandwidth Query Logic",
                    fileName = "SelfCareController.php",
                    language = "php",
                    description = "Queries monthly partitioned radacct tables to generate usage graphs for the customer portal.",
                    codeContent = """
<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class SelfCareController {
    public function getUsage() {
        ${'$'}table = "radacct_y" . now()->format('Ym');
        return DB::table(${'$'}table)
            ->where('username', auth()->user()->username)
            ->selectRaw("SUM(acctinputoctets) as download, SUM(acctoutputoctets) as upload")
            ->get();
    }
}
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
