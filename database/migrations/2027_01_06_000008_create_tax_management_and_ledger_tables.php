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
        $driver = Schema::getConnection()->getDriverName();

        // 1. tax_codes
        Schema::create('tax_codes', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 32);
            $table->string('name', 128);
            $table->string('tax_type', 32); // VAT_PPN, LOCAL_PB1, WITHHOLDING_PPH, EXEMPT
            $table->string('calculation_type', 32)->default('EXCLUSIVE'); // EXCLUSIVE, INCLUSIVE
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_tc_tenant_id');
            $table->unique(['tenant_id', 'code'], 'uq_tc_code');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign('tenant_id', 'fk_tc_tenant')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');
            }
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE tax_codes ADD CONSTRAINT chk_tc_tax_type
                CHECK (tax_type IN ('VAT_PPN', 'LOCAL_PB1', 'WITHHOLDING_PPH', 'EXEMPT'))");

            DB::statement("ALTER TABLE tax_codes ADD CONSTRAINT chk_tc_calc_type
                CHECK (calculation_type IN ('EXCLUSIVE', 'INCLUSIVE'))");
        }

        // 2. tax_rates (Effective-Dated)
        Schema::create('tax_rates', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('tax_code_id');
            $table->decimal('rate_percent', 8, 4);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_tr_tenant_id');
            $table->index(['tenant_id', 'tax_code_id', 'effective_from', 'effective_to'], 'idx_tr_effective');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign(['tenant_id', 'tax_code_id'], 'fk_tr_code')
                    ->references(['tenant_id', 'id'])
                    ->on('tax_codes')
                    ->onDelete('cascade');
            }
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT chk_tr_rate
                CHECK (rate_percent >= 0.0000)');
        }

        // 3. tax_account_mappings
        Schema::create('tax_account_mappings', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('tax_code_id');
            $table->unsignedBigInteger('sales_tax_account_id');
            $table->unsignedBigInteger('purchase_tax_account_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_tam_tenant_id');
            $table->unique(['tenant_id', 'tax_code_id'], 'uq_tam_code');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign(['tenant_id', 'tax_code_id'], 'fk_tam_code')
                    ->references(['tenant_id', 'id'])
                    ->on('tax_codes')
                    ->onDelete('cascade');

                $table->foreign(['tenant_id', 'sales_tax_account_id'], 'fk_tam_sales_acc')
                    ->references(['tenant_id', 'id'])
                    ->on('chart_of_accounts')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'purchase_tax_account_id'], 'fk_tam_purch_acc')
                    ->references(['tenant_id', 'id'])
                    ->on('chart_of_accounts')
                    ->onDelete('restrict');
            }
        });

        // 4. tax_ledger_entries (Buku Pembantu Pajak Imutabel)
        Schema::create('tax_ledger_entries', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('tax_code_id');
            $table->unsignedBigInteger('tax_rate_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('journal_line_id')->nullable();
            $table->string('entry_number', 64);
            $table->date('transaction_date');
            $table->string('tax_direction', 32); // OUTPUT_TAX, INPUT_TAX
            $table->decimal('tax_base_amount', 18, 2);
            $table->decimal('applied_rate', 8, 4);
            $table->decimal('tax_amount', 18, 2);
            $table->string('counterparty_type', 32)->nullable();
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->string('counterparty_name', 150)->nullable();
            $table->string('counterparty_tax_id', 50)->nullable();
            $table->string('invoice_reference', 100)->nullable();
            $table->string('tax_invoice_number', 50)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'uq_tle_tenant_id');
            $table->unique(['tenant_id', 'entry_number'], 'uq_tle_number');
            $table->index(['tenant_id', 'tax_code_id', 'tax_direction', 'transaction_date'], 'idx_tle_report');

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $table->foreign(['tenant_id', 'tax_code_id'], 'fk_tle_code')
                    ->references(['tenant_id', 'id'])
                    ->on('tax_codes')
                    ->onDelete('restrict');

                $table->foreign(['tenant_id', 'journal_entry_id'], 'fk_tle_journal')
                    ->references(['tenant_id', 'id'])
                    ->on('journal_entries')
                    ->onDelete('restrict');
            }
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE tax_ledger_entries ADD CONSTRAINT chk_tle_direction
                CHECK (tax_direction IN ('OUTPUT_TAX', 'INPUT_TAX'))");

            DB::statement('ALTER TABLE tax_ledger_entries ADD CONSTRAINT chk_tle_amounts
                CHECK (tax_base_amount >= 0.00 AND tax_amount >= 0.00 AND applied_rate >= 0.0000)');

            // Trigger Imutabilitas: UPDATE & DELETE dilarang
            DB::unprepared("
                CREATE TRIGGER trg_tax_ledger_entries_before_update BEFORE UPDATE ON tax_ledger_entries
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-09: Buku pembantu pajak (tax_ledger_entries) bersifat imutabel dan tidak dapat diubah.';
                END;
            ");

            DB::unprepared("
                CREATE TRIGGER trg_tax_ledger_entries_before_delete BEFORE DELETE ON tax_ledger_entries
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'FIN-M7-09: Buku pembantu pajak (tax_ledger_entries) bersifat imutabel dan tidak dapat dihapus.';
                END;
            ");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_tax_ledger_entries_before_delete;');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_tax_ledger_entries_before_update;');
        }

        Schema::dropIfExists('tax_ledger_entries');
        Schema::dropIfExists('tax_account_mappings');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_codes');
    }
};
