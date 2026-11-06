<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * TR-007: Multi-Currency Foundation & Floating Exchange Rates.
     */
    public function up(): void
    {
        if (! Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('code', 3);
                $table->string('name', 64);
                $table->string('symbol', 10);
                $table->unsignedTinyInteger('decimal_places')->default(2);
                $table->boolean('is_base')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->unique(['tenant_id', 'code'], 'currencies_tenant_code_unique');
                $table->index(['tenant_id', 'is_active'], 'idx_currencies_tenant_active');
            });
        }

        if (! Schema::hasTable('exchange_rates')) {
            Schema::create('exchange_rates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('from_currency', 3);
                $table->string('to_currency', 3);
                $table->decimal('rate', 18, 6);
                $table->decimal('inverse_rate', 18, 6);
                $table->date('effective_date');
                $table->string('rate_source', 32)->default('BANK_INDONESIA');
                $table->timestamps();

                $table->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->unique(['tenant_id', 'from_currency', 'to_currency', 'effective_date'], 'exch_rate_tenant_pair_date_unique');
                $table->index(['tenant_id', 'effective_date'], 'idx_exch_rate_tenant_date');
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'currency_code')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('currency_code', 3)->default('IDR')->after('order_status');
                $table->decimal('exchange_rate', 18, 6)->default(1.000000)->after('currency_code');
            });
        }

        if (Schema::hasTable('sales_orders') && ! Schema::hasColumn('sales_orders', 'currency_code')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->string('currency_code', 3)->default('IDR');
                $table->decimal('exchange_rate', 18, 6)->default(1.000000);
            });
        }

        if (Schema::hasTable('journal_lines') && ! Schema::hasColumn('journal_lines', 'foreign_currency_code')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                $table->string('foreign_currency_code', 3)->nullable()->after('credit_amount');
                $table->decimal('foreign_debit_amount', 15, 2)->nullable()->after('foreign_currency_code');
                $table->decimal('foreign_credit_amount', 15, 2)->nullable()->after('foreign_debit_amount');
                $table->decimal('exchange_rate', 18, 6)->nullable()->after('foreign_credit_amount');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('journal_lines') && Schema::hasColumn('journal_lines', 'foreign_currency_code')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                $table->dropColumn(['foreign_currency_code', 'foreign_debit_amount', 'foreign_credit_amount', 'exchange_rate']);
            });
        }

        if (Schema::hasTable('sales_orders') && Schema::hasColumn('sales_orders', 'currency_code')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->dropColumn(['currency_code', 'exchange_rate']);
            });
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'currency_code')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn(['currency_code', 'exchange_rate']);
            });
        }

        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};

