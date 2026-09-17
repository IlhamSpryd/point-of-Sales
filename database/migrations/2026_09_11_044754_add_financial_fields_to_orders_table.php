<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'subtotal_amount')) {
                $table->decimal('subtotal_amount', 15, 2)->default(0)->after('order_date');
            }
            if (! Schema::hasColumn('orders', 'tax_amount')) {
                $table->decimal('tax_amount', 15, 2)->default(0)->after('subtotal_amount');
            }
            if (! Schema::hasColumn('orders', 'cash_received')) {
                $table->decimal('cash_received', 15, 2)->nullable()->after('order_change');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['subtotal_amount', 'tax_amount', 'cash_received']);
        });
    }
};
