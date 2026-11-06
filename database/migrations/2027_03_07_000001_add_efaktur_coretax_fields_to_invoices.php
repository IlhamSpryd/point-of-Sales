<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TR-004 / DEC-003: Abstraksi Lapisan Integrasi e-Faktur Pajak CoreTax DJP.
     * Menambahkan referensi faktur pajak (NSFP dan status) pada customer_invoices dan supplier_invoices.
     */
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->string('tax_invoice_serial_number', 32)->nullable()->after('tax_amount');
            $table->string('tax_invoice_status', 32)->default('draft')->after('tax_invoice_serial_number');
            $table->index(['tenant_id', 'tax_invoice_serial_number'], 'idx_ci_tax_invoice_serial');
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->string('tax_invoice_serial_number', 32)->nullable()->after('tax_amount');
            $table->string('tax_invoice_status', 32)->default('draft')->after('tax_invoice_serial_number');
            $table->index(['tenant_id', 'tax_invoice_serial_number'], 'idx_si_tax_invoice_serial');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropIndex('idx_ci_tax_invoice_serial');
            $table->dropColumn(['tax_invoice_serial_number', 'tax_invoice_status']);
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropIndex('idx_si_tax_invoice_serial');
            $table->dropColumn(['tax_invoice_serial_number', 'tax_invoice_status']);
        });
    }
};

