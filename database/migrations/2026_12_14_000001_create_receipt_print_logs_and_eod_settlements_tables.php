<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Increment 5: Receipt Reprint Logs & End-of-Day Settlements.
     */
    public function up(): void
    {
        // 1. Tambah kolom print_count pada orders jika belum ada
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'print_count')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedInteger('print_count')->default(0)->after('order_status');
            });
        }

        // 2. Tabel receipt_print_logs
        if (! Schema::hasTable('receipt_print_logs')) {
            Schema::create('receipt_print_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('device_id')->nullable();
                $table->unsignedBigInteger('shift_id')->nullable();
                $table->unsignedBigInteger('printed_by_user_id');
                $table->unsignedInteger('print_sequence');
                $table->boolean('is_reprint')->default(false);
                $table->string('reprint_reason', 255)->nullable();
                $table->timestamp('printed_at');
                $table->timestamps();

                $table->index(['tenant_id', 'store_id', 'order_id'], 'idx_rpl_tenant_store_order');

                $table->foreign(['tenant_id', 'store_id'], 'fk_rpl_store')
                    ->references(['tenant_id', 'id'])->on('stores')->onDelete('restrict');
                $table->foreign(['tenant_id', 'order_id'], 'fk_rpl_order')
                    ->references(['tenant_id', 'id'])->on('orders')->onDelete('restrict');
                $table->foreign(['tenant_id', 'printed_by_user_id'], 'fk_rpl_user')
                    ->references(['tenant_id', 'id'])->on('users')->onDelete('restrict');
                $table->foreign('device_id', 'fk_rpl_device')
                    ->references('id')->on('devices')->nullOnDelete();
                $table->foreign('shift_id', 'fk_rpl_shift')
                    ->references('id')->on('shifts')->nullOnDelete();
            });
        }

        // 3. Tabel eod_settlements
        if (! Schema::hasTable('eod_settlements')) {
            Schema::create('eod_settlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->date('business_date');
                $table->string('status', 32)->default('closed');
                $table->unsignedInteger('total_shifts_count');
                $table->decimal('gross_sales', 15, 2);
                $table->decimal('total_discounts', 15, 2);
                $table->decimal('total_returns_refunds', 15, 2);
                $table->decimal('total_voids', 15, 2);
                $table->decimal('net_sales', 15, 2);
                $table->decimal('total_tax', 15, 2);
                $table->decimal('total_service_charge', 15, 2);
                $table->decimal('expected_cash_total', 15, 2);
                $table->decimal('counted_cash_total', 15, 2);
                $table->decimal('cash_discrepancy_total', 15, 2);
                $table->json('payment_breakdown_snapshot');
                $table->unsignedBigInteger('closed_by_user_id');
                $table->timestamp('closed_at');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'store_id', 'business_date'], 'uq_eod_store_business_date');

                $table->foreign(['tenant_id', 'store_id'], 'fk_eod_store')
                    ->references(['tenant_id', 'id'])->on('stores')->onDelete('restrict');
                $table->foreign(['tenant_id', 'closed_by_user_id'], 'fk_eod_user')
                    ->references(['tenant_id', 'id'])->on('users')->onDelete('restrict');
            });

            DB::statement("ALTER TABLE eod_settlements ADD CONSTRAINT chk_eod_status_enum CHECK (status IN ('open', 'closed'))");

            // Immutability Triggers
            DB::unprepared("
                CREATE TRIGGER trg_eod_settlements_no_delete
                BEFORE DELETE ON eod_settlements
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Penghapusan data EOD settlement dilarang (immutable audit record).';
                END;
            ");

            DB::unprepared("
                CREATE TRIGGER trg_eod_settlements_immutable_update
                BEFORE UPDATE ON eod_settlements
                FOR EACH ROW
                BEGIN
                    IF OLD.status = 'closed' THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'EOD settlement yang sudah berstatus closed tidak dapat diubah (immutable).';
                    END IF;
                END;
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_eod_settlements_immutable_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_eod_settlements_no_delete');

        Schema::dropIfExists('eod_settlements');
        Schema::dropIfExists('receipt_print_logs');

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'print_count')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('print_count');
            });
        }
    }
};
