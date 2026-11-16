<?php
// File: database/migrations/2026_11_12_100004_create_invoices_table.php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->string('invoice_number', 40)->unique('idx_invoices_number');
            $table->decimal('amount', 10, 2);
            $table->string('status', 24)->default(InvoiceStatus::UNPAID->value)->index('idx_invoices_status');
            $table->string('payment_gateway', 32)->nullable();
            $table->string('transaction_id', 64)->nullable()->unique('idx_invoices_trx_id');
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
