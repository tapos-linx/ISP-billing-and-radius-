<?php
// File: database/migrations/2026_11_12_100001_create_nas_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nas')) {
            return;
        }

        Schema::create('nas', function (Blueprint $table): void {
            $table->id();
            $table->string('nasname', 128)->unique('idx_nas_nasname');
            $table->string('shortname', 32);
            $table->string('type', 30)->default('mikrotik');
            $table->unsignedSmallInteger('ports')->default(3799);
            $table->string('secret', 60);
            $table->string('server', 64)->nullable();
            $table->string('community', 50)->nullable();
            $table->string('description', 200)->default('MikroTik RouterOS v7 BNG');
            $table->string('pop_division', 32)->default('Dhaka')->index('idx_nas_pop_division');
            $table->boolean('is_active')->default(true)->index('idx_nas_is_active');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nas');
    }
};
