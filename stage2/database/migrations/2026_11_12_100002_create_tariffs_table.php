<?php
// File: database/migrations/2026_11_12_100002_create_tariffs_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 40)->unique('idx_tariffs_code');
            $table->unsignedInteger('download_mbps');
            $table->unsignedInteger('upload_mbps');
            $table->string('mikrotik_rate_limit', 128);
            $table->string('framed_pool', 64)->default('pppoe_active_pool');
            $table->string('walled_garden_pool', 64)->default('walled_garden_pool');
            $table->decimal('monthly_price', 10, 2);
            $table->unsignedSmallInteger('validity_days')->default(30);
            $table->boolean('is_active')->default(true)->index('idx_tariffs_is_active');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};
