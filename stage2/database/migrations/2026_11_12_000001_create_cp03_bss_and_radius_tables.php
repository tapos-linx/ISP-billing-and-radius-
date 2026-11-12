<?php
// File: database/migrations/2026_11_12_000001_create_cp03_bss_and_radius_tables.php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\SubscriberStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. FreeRADIUS 3.2 Shared Tables (rlm_sql compatible)
        if (! Schema::hasTable('radcheck')) {
            Schema::create('radcheck', function (Blueprint $table): void {
                $table->id();
                $table->string('username', 64)->default('')->index('idx_radcheck_username');
                $table->string('attribute', 64)->default('Cleartext-Password');
                $table->char('op', 2)->default(':=');
                $table->string('value', 253)->default('');
                $table->unsignedTinyInteger('shard_id')->default(0)->index('idx_radcheck_shard');
            });
        }

        if (! Schema::hasTable('radreply')) {
            Schema::create('radreply', function (Blueprint $table): void {
                $table->id();
                $table->string('username', 64)->default('')->index('idx_radreply_username');
                $table->string('attribute', 64)->default('');
                $table->char('op', 2)->default(':=');
                $table->string('value', 253)->default('');
                $table->unsignedTinyInteger('shard_id')->default(0)->index('idx_radreply_shard');
            });
        }

        if (! Schema::hasTable('nas')) {
            Schema::create('nas', function (Blueprint $table): void {
                $table->id();
                $table->string('nasname', 128)->unique('idx_nas_nasname');
                $table->string('shortname', 32);
                $table->string('type', 30)->default('mikrotik');
                $table->integer('ports')->default(3799);
                $table->string('secret', 60);
                $table->string('server', 64)->nullable();
                $table->string('community', 50)->nullable();
                $table->string('description', 200)->default('MikroTik RouterOS v7 BNG');
                $table->string('pop_division', 32)->default('Dhaka');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 2. Carrier BSS Core Tables (PostgreSQL 16 + Citus 128-Shard Compatible)
        Schema::create('tariffs', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 40)->unique();
            $table->unsignedInteger('download_mbps');
            $table->unsignedInteger('upload_mbps');
            $table->string('mikrotik_rate_limit', 128);
            $table->string('framed_pool', 64)->default('pppoe_active_pool');
            $table->string('walled_garden_pool', 64)->default('walled_garden_pool');
            $table->decimal('monthly_price', 10, 2);
            $table->unsignedSmallInteger('validity_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscribers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tariff_id')->constrained('tariffs')->restrictOnDelete();
            $table->foreignId('nas_id')->nullable()->constrained('nas')->nullOnDelete();
            $table->string('username', 64)->unique('idx_subscribers_username');
            $table->string('pppoe_password', 128);
            $table->string('full_name', 120);
            $table->string('phone', 20)->index('idx_subscribers_phone');
            $table->string('nid_number', 24)->nullable();
            $table->string('pop_division', 32)->default('Dhaka')->index('idx_subscribers_division');
            $table->string('ont_serial', 64)->nullable()->unique();
            $table->decimal('optical_rx_dbm', 5, 2)->default(-19.50);
            $table->ipAddress('static_ipv4')->nullable();
            $table->unsignedTinyInteger('cgnat_shard_id')->default(0)->index('idx_subscribers_shard');
            $table->string('status', 24)->default(SubscriberStatus::ACTIVE->value)->index('idx_subscribers_status');
            $table->timestampTz('expires_at')->index('idx_subscribers_expires_at');
            $table->timestampTz('last_coa_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->string('invoice_number', 40)->unique();
            $table->decimal('amount', 10, 2);
            $table->string('status', 24)->default(InvoiceStatus::UNPAID->value)->index('idx_invoices_status');
            $table->string('payment_gateway', 32)->nullable();
            $table->string('transaction_id', 64)->nullable()->unique('idx_invoices_trx_id');
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscribers');
        Schema::dropIfExists('tariffs');
        Schema::dropIfExists('nas');
        Schema::dropIfExists('radreply');
        Schema::dropIfExists('radcheck');
    }
};
