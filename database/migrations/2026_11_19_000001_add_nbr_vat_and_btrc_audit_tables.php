<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('subtotal_bdt', 12, 2)->default(0)->after('subscriber_id');
            $table->decimal('vat_rate_percent', 5, 2)->default(5.00)->after('subtotal_bdt'); // 5% ISP or 15% standard
            $table->decimal('vat_amount_bdt', 12, 2)->default(0)->after('vat_rate_percent');
            $table->decimal('total_bdt', 12, 2)->default(0)->after('vat_amount_bdt');
            $table->string('isp_bin_number', 32)->nullable()->after('total_bdt');
            $table->string('mushak_6_3_number', 64)->nullable()->unique()->after('isp_bin_number');
            $table->string('customer_bin_nid', 32)->nullable()->after('mushak_6_3_number');
        });

        Schema::create('btrc_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('subscriber_id')->index();
            $table->string('actor_type', 32); // admin, system, mfs_webhook
            $table->string('action', 64);     // activate, suspend, rate_change, ip_assign
            $table->ipAddress('framed_ip')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('retained_until')->index(); // BTRC retention window (180d+ hot / archive)
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('btrc_audit_logs');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'subtotal_bdt', 'vat_rate_percent', 'vat_amount_bdt',
                'total_bdt', 'isp_bin_number', 'mushak_6_3_number', 'customer_bin_nid'
            ]);
        });
    }
};
