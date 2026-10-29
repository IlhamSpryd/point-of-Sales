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
        $driver = DB::connection()->getDriverName();

        // 1. Kolom aditif pada supplier_invoices
        Schema::table('supplier_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_invoices', 'paid_amount')) {
                $table->decimal('paid_amount', 18, 2)->default(0.00)->after('outstanding_amount');
            }
            if (! Schema::hasColumn('supplier_invoices', 'gl_journal_entry_id')) {
                $table->unsignedBigInteger('gl_journal_entry_id')->nullable()->after('paid_amount');
            }
        });

        // Add foreign key if not already added
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'gl_journal_entry_id'], 'fk_sinv_journal')
                ->references(['tenant_id', 'id'])->on('journal_entries')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        // 2. Tabel ap_payments (Header Pembayaran Supplier)
        Schema::create('ap_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('cash_bank_account_id');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('payment_number', 64);
            $table->date('payment_date');
            $table->string('payment_method', 32)->default('BANK_TRANSFER');
            $table->char('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 18, 6)->default(1.000000);
            $table->decimal('total_payment_amount', 18, 2);
            $table->decimal('allocated_amount', 18, 2)->default(0.00);
            $table->decimal('unapplied_prepayment_amount', 18, 2)->default(0.00);
            $table->decimal('early_discount_amount', 18, 2)->default(0.00);
            $table->decimal('bank_fee_amount', 18, 2)->default(0.00);
            $table->string('reference_number', 100)->nullable();
            $table->string('status', 32)->default('DRAFT'); // DRAFT, PENDING_APPROVAL, POSTED, VOIDED
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 128);
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('voided_by_user_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            // Multi-tenant unique constraints
            $table->unique(['tenant_id', 'id'], 'uq_appay_tenant_id');
            $table->unique(['tenant_id', 'payment_number'], 'uq_appay_number');
            $table->unique(['tenant_id', 'idempotency_key'], 'uq_appay_idempotency');

            $table->index(['tenant_id', 'supplier_id', 'status', 'payment_date'], 'idx_appay_supplier_status');
            $table->index(['tenant_id', 'store_id', 'payment_date'], 'idx_appay_store_date');

            // Foreign Keys
            $table->foreign(['tenant_id', 'supplier_id'], 'fk_appay_supplier')
                ->references(['tenant_id', 'id'])->on('suppliers')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'store_id'], 'fk_appay_store')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'cash_bank_account_id'], 'fk_appay_cash_bank')
                ->references(['tenant_id', 'id'])->on('cash_bank_accounts')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'journal_entry_id'], 'fk_appay_journal')
                ->references(['tenant_id', 'id'])->on('journal_entries')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('created_by_user_id', 'fk_appay_created_by')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('approved_by_user_id', 'fk_appay_approved_by')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('posted_by_user_id', 'fk_appay_posted_by')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('voided_by_user_id', 'fk_appay_voided_by')
                ->references('id')->on('users')
                ->onDelete('restrict');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE ap_payments ADD CONSTRAINT chk_appay_status
                CHECK (status IN ('DRAFT', 'PENDING_APPROVAL', 'POSTED', 'VOIDED'))");

            DB::statement('ALTER TABLE ap_payments ADD CONSTRAINT chk_appay_amounts
                CHECK (total_payment_amount > 0 AND allocated_amount >= 0 AND unapplied_prepayment_amount >= 0 AND early_discount_amount >= 0 AND bank_fee_amount >= 0)');
        }

        // 3. Tabel ap_payment_allocations (Alokasi Pembayaran ke Supplier Invoice)
        Schema::create('ap_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('ap_payment_id');
            $table->unsignedBigInteger('supplier_invoice_id');
            $table->decimal('allocated_amount', 18, 2);
            $table->decimal('discount_taken_amount', 18, 2)->default(0.00);
            $table->string('status', 32)->default('POSTED'); // POSTED, REVERSED
            $table->string('idempotency_key', 128);
            $table->timestamps();

            // Multi-tenant unique constraints
            $table->unique(['tenant_id', 'id'], 'uq_appa_tenant_id');
            $table->unique(['tenant_id', 'idempotency_key'], 'uq_appa_idempotency');

            $table->index(['tenant_id', 'supplier_invoice_id', 'status'], 'idx_appa_invoice');
            $table->index(['tenant_id', 'ap_payment_id'], 'idx_appa_payment');

            // Foreign Keys
            $table->foreign(['tenant_id', 'ap_payment_id'], 'fk_appa_payment')
                ->references(['tenant_id', 'id'])->on('ap_payments')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'supplier_invoice_id'], 'fk_appa_invoice')
                ->references(['tenant_id', 'id'])->on('supplier_invoices')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'store_id'], 'fk_appa_store')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE ap_payment_allocations ADD CONSTRAINT chk_appa_status
                CHECK (status IN ('POSTED', 'REVERSED'))");

            DB::statement('ALTER TABLE ap_payment_allocations ADD CONSTRAINT chk_appa_amounts
                CHECK (allocated_amount > 0 AND discount_taken_amount >= 0)');
        }

        // 4. Tabel ap_ledger_entries (Append-Only Subledger Hutang Usaha)
        Schema::create('ap_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('supplier_invoice_id')->nullable();
            $table->unsignedBigInteger('ap_payment_id')->nullable();
            $table->unsignedBigInteger('purchase_debit_note_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('entry_number', 64);
            $table->date('effective_date');
            $table->string('entry_type', 32); // INVOICE_CREDIT, PAYMENT_DEBIT, DEBIT_NOTE_APPLIED, CREDIT_ADJUSTMENT, REVERSAL
            $table->decimal('debit_amount', 18, 2)->default(0.00);
            $table->decimal('credit_amount', 18, 2)->default(0.00);
            $table->decimal('running_balance', 18, 2)->default(0.00);
            $table->string('idempotency_key', 128);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            // Multi-tenant unique constraints
            $table->unique(['tenant_id', 'id'], 'uq_aple_tenant_id');
            $table->unique(['tenant_id', 'idempotency_key'], 'uq_aple_idempotency');

            $table->index(['tenant_id', 'supplier_id', 'effective_date', 'id'], 'idx_aple_supplier_date');
            $table->index(['tenant_id', 'supplier_invoice_id'], 'idx_aple_invoice');
            $table->index(['tenant_id', 'entry_type'], 'idx_aple_type');

            // Foreign Keys
            $table->foreign(['tenant_id', 'supplier_id'], 'fk_aple_supplier')
                ->references(['tenant_id', 'id'])->on('suppliers')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'store_id'], 'fk_aple_store')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'supplier_invoice_id'], 'fk_aple_invoice')
                ->references(['tenant_id', 'id'])->on('supplier_invoices')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'ap_payment_id'], 'fk_aple_payment')
                ->references(['tenant_id', 'id'])->on('ap_payments')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'journal_entry_id'], 'fk_aple_journal')
                ->references(['tenant_id', 'id'])->on('journal_entries')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('created_by_user_id', 'fk_aple_created_by')
                ->references('id')->on('users')
                ->onDelete('restrict');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE ap_ledger_entries ADD CONSTRAINT chk_aple_entry_type
                CHECK (entry_type IN ('INVOICE_CREDIT', 'PAYMENT_DEBIT', 'DEBIT_NOTE_APPLIED', 'CREDIT_ADJUSTMENT', 'REVERSAL'))");

            DB::statement('ALTER TABLE ap_ledger_entries ADD CONSTRAINT chk_aple_amounts
                CHECK (debit_amount >= 0 AND credit_amount >= 0)');

            // Trigger append-only pada ap_ledger_entries: larang delete dan larang update selain journal_entry_id
            DB::unprepared("
                CREATE TRIGGER trg_ap_ledger_entries_before_update BEFORE UPDATE ON ap_ledger_entries
                FOR EACH ROW
                BEGIN
                    IF OLD.journal_entry_id IS NOT NULL AND OLD.journal_entry_id <> NEW.journal_entry_id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Modifikasi journal_entry_id pada ap_ledger_entries yang sudah terpasang dilarang.';
                    END IF;
                    IF OLD.debit_amount <> NEW.debit_amount
                       OR OLD.credit_amount <> NEW.credit_amount
                       OR OLD.running_balance <> NEW.running_balance
                       OR OLD.entry_type <> NEW.entry_type
                       OR OLD.effective_date <> NEW.effective_date
                       OR OLD.supplier_id <> NEW.supplier_id
                       OR OLD.tenant_id <> NEW.tenant_id THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Buku pembantu AP (ap_ledger_entries) bersifat imutabel (append-only).';
                    END IF;
                END;
            ");

            DB::unprepared("
                CREATE TRIGGER trg_ap_ledger_entries_before_delete BEFORE DELETE ON ap_ledger_entries
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-08: Penghapusan baris pada ap_ledger_entries dilarang keras.';
                END;
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ap_ledger_entries_before_delete;');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_ap_ledger_entries_before_update;');
        }

        Schema::dropIfExists('ap_ledger_entries');
        Schema::dropIfExists('ap_payment_allocations');
        Schema::dropIfExists('ap_payments');

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropForeign('fk_sinv_journal');
            $table->dropColumn(['paid_amount', 'gl_journal_entry_id']);
        });
    }
};
