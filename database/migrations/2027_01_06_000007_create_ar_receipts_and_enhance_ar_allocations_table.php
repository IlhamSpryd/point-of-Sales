<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1. Tabel ar_receipts (Penerimaan Kas/Bank dari Piutang Pelanggan)
        if (! Schema::hasTable('ar_receipts')) {
            Schema::create('ar_receipts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id')->nullable();
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('cash_bank_account_id')->nullable();
                $table->string('receipt_number', 64);
                $table->date('receipt_date');
                $table->decimal('total_received_amount', 18, 2);
                $table->decimal('total_allocated_amount', 18, 2)->default(0.00);
                $table->decimal('unapplied_credit_amount', 18, 2)->default(0.00);
                $table->decimal('bank_fee_amount', 18, 2)->default(0.00);
                $table->string('payment_method', 50)->default('bank_transfer');
                $table->string('payment_reference', 100)->nullable();
                $table->string('status', 32)->default('POSTED'); // POSTED, VOIDED, REVERSED
                $table->unsignedBigInteger('journal_entry_id')->nullable();
                $table->unsignedBigInteger('cash_bank_transaction_id')->nullable();
                $table->string('idempotency_key', 128)->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('posted_by_user_id')->nullable();
                $table->unsignedBigInteger('voided_by_user_id')->nullable();
                $table->text('void_reason')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'uq_ar_receipts_tenant_id');
                $table->unique(['tenant_id', 'receipt_number'], 'uq_ar_receipts_number');
                $table->unique(['tenant_id', 'idempotency_key'], 'uq_ar_receipts_idempotency');

                $table->index(['tenant_id', 'customer_id'], 'idx_arr_tenant_customer');
                $table->index(['tenant_id', 'cash_bank_account_id'], 'idx_arr_tenant_cba');
                $table->index(['tenant_id', 'status', 'receipt_date'], 'idx_arr_tenant_status_date');

                $table->foreign(['tenant_id', 'customer_id'], 'fk_arr_customer')
                    ->references(['tenant_id', 'id'])->on('customers')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'cash_bank_account_id'], 'fk_arr_cash_bank_account')
                    ->references(['tenant_id', 'id'])->on('cash_bank_accounts')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'journal_entry_id'], 'fk_arr_journal')
                    ->references(['tenant_id', 'id'])->on('journal_entries')
                    ->onDelete('restrict')->onUpdate('cascade');

                $table->foreign(['tenant_id', 'cash_bank_transaction_id'], 'fk_arr_cbt')
                    ->references(['tenant_id', 'id'])->on('cash_bank_transactions')
                    ->onDelete('restrict')->onUpdate('cascade');
            });

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE ar_receipts ADD CONSTRAINT chk_arr_amounts
                    CHECK (total_received_amount >= 0 AND total_allocated_amount >= 0 AND unapplied_credit_amount >= 0)');
            }
        }

        // 2. Additive columns pada ar_payment_allocations
        Schema::table('ar_payment_allocations', function (Blueprint $table) {
            if (! Schema::hasColumn('ar_payment_allocations', 'ar_receipt_id')) {
                $table->unsignedBigInteger('ar_receipt_id')->nullable()->after('customer_invoice_id');
                $table->foreign(['tenant_id', 'ar_receipt_id'], 'fk_arpa_receipt')
                    ->references(['tenant_id', 'id'])->on('ar_receipts')
                    ->onDelete('restrict')->onUpdate('cascade');
            }
            if (! Schema::hasColumn('ar_payment_allocations', 'cash_bank_account_id')) {
                $table->unsignedBigInteger('cash_bank_account_id')->nullable()->after('ar_receipt_id');
                $table->foreign(['tenant_id', 'cash_bank_account_id'], 'fk_arpa_cba')
                    ->references(['tenant_id', 'id'])->on('cash_bank_accounts')
                    ->onDelete('restrict')->onUpdate('cascade');
            }
            if (! Schema::hasColumn('ar_payment_allocations', 'journal_entry_id')) {
                $table->unsignedBigInteger('journal_entry_id')->nullable()->after('cash_bank_account_id');
                $table->foreign(['tenant_id', 'journal_entry_id'], 'fk_arpa_journal')
                    ->references(['tenant_id', 'id'])->on('journal_entries')
                    ->onDelete('restrict')->onUpdate('cascade');
            }
        });

        // 3. Additive columns pada customer_invoices
        Schema::table('customer_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_invoices', 'gl_journal_entry_id')) {
                $table->unsignedBigInteger('gl_journal_entry_id')->nullable()->after('status');
                $table->foreign(['tenant_id', 'gl_journal_entry_id'], 'fk_cinv_gl_journal')
                    ->references(['tenant_id', 'id'])->on('journal_entries')
                    ->onDelete('restrict')->onUpdate('cascade');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('customer_invoices', 'gl_journal_entry_id')) {
                $table->dropForeign('fk_cinv_gl_journal');
                $table->dropColumn('gl_journal_entry_id');
            }
        });

        Schema::table('ar_payment_allocations', function (Blueprint $table) {
            if (Schema::hasColumn('ar_payment_allocations', 'journal_entry_id')) {
                $table->dropForeign('fk_arpa_journal');
                $table->dropColumn('journal_entry_id');
            }
            if (Schema::hasColumn('ar_payment_allocations', 'cash_bank_account_id')) {
                $table->dropForeign('fk_arpa_cba');
                $table->dropColumn('cash_bank_account_id');
            }
            if (Schema::hasColumn('ar_payment_allocations', 'ar_receipt_id')) {
                $table->dropForeign('fk_arpa_receipt');
                $table->dropColumn('ar_receipt_id');
            }
        });

        Schema::dropIfExists('ar_receipts');
    }
};
