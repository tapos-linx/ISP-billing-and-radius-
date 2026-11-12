<?php
// File: database/migrations/2026_11_12_100003_create_subscribers_table.php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('nas_id')->nullable()->constrained('nas')->nullOnDelete();
            $table->foreignId('tariff_id')->constrained('tariffs')->restrictOnDelete();
            $table->string('username', 64)->unique('idx_subscribers_username');
            $table->string('pppoe_password', 128);
            $table->string('full_name', 120);
            $table->string('phone', 20)->index('idx_subscribers_phone');
            $table->string('nid_number', 24)->nullable();
            $table->string('pop_division', 32)->default('Dhaka')->index('idx_subscribers_division');
            $table->string('ont_serial', 64)->nullable()->unique('idx_subscribers_ont_serial');
            $table->decimal('optical_rx_dbm', 5, 2)->default(-19.50);
            $table->ipAddress('static_ipv4')->nullable();
            $table->unsignedTinyInteger('cgnat_shard_id')->default(0)->index('idx_subscribers_shard');
            $table->string('status', 24)->default(SubscriberStatus::ACTIVE->value)->index('idx_subscribers_status');
            $table->timestampTz('expires_at')->index('idx_subscribers_expires_at');
            $table->timestampTz('last_coa_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
