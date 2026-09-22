<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE7] Enterprise Data Architecture Expansion — Modul Split Payment | 2026-09-22
return new class extends Migration
{
    /**
     * Ledger multi-tender: SATU order bisa dilunasi dengan LEBIH DARI SATU
     * metode pembayaran sekaligus (mis. Rp50.000 tunai + sisanya QRIS).
     * `orders.payment_method` (kolom tunggal existing) TETAP DIPERTAHANKAN
     * sebagai "metode dominan/pertama" agar TIDAK ADA breaking change pada
     * dashboard/laporan yang sudah berjalan (DashboardService, ReportService)
     * -- tabel ini adalah RINCIAN tambahan, bukan pengganti.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete: order finansial TIDAK PERNAH hard-deleted
            // di sistem ini (konsisten dengan seluruh tabel transaksi lain
            // -- order_items, stock_movements, dst).
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('payment_method', 20); // cash | qris | ewallet | card
            // Rupiah tanpa subunit desimal praktis -- konsisten dengan
            // orders.order_amount (bigint unsigned), BUKAN decimal.
            $table->unsignedBigInteger('amount');
            $table->string('reference_number')->nullable(); // trace EDC / transaction_id e-wallet / Midtrans
            $table->string('status', 20)->default('captured'); // pending | captured | failed | refunded
            $table->timestamp('captured_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->restrictOnDelete();
            // Mencegah dua leg pembayaran identik ter-insert dua kali akibat
            // double-submit UI kasir atau retry webhook e-wallet/EDC.
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            // Covering index untuk query hot-path: "berapa total yang sudah
            // captured untuk order ini?" -- dieksekusi pada SETIAP render
            // layar split-payment kasir dan pada validasi checkout.
            $table->index(['order_id', 'status'], 'payments_order_status_index');
        });

        if (DB::getDriverName() !== 'sqlite') { DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount_positive CHECK (amount > 0)'); }
        if (DB::getDriverName() !== 'sqlite') { DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_method_enum CHECK (payment_method IN ('cash','qris','ewallet','card'))"); }
        if (DB::getDriverName() !== 'sqlite') { DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_status_enum CHECK (status IN ('pending','captured','failed','refunded'))"); }

        // Defense-in-depth (pola sama dengan SEC-006 di TransactionService):
        // validasi Service layer bisa dilewati oleh query manual. Trigger
        // ini menolak INSERT apa pun yang membuat AKUMULASI pembayaran
        // 'captured' pada satu order melebihi order_amount-nya. Refund/void
        // tetap bisa lewat status lain (failed/refunded) yang tidak
        // dihitung dalam SUM ini.
        //
        // PERINGATAN KONKURENSI (baca narasi resiliency di jawaban utama):
        // trigger ini BUKAN pengganti lockForUpdate() pada baris `orders` --
        // dua INSERT captured yang benar-benar bersamaan pada order yang
        // sama tetap bisa lolos bersamaan dari SELECT SUM(...) yang sama,
        // persis seperti race condition yang sudah didokumentasikan proyek
        // ini di TransactionConcurrencyTest. Service layer WAJIB membungkus
        // insert payment dalam DB::transaction() + Order::lockForUpdate().
        if (DB::getDriverName() !== 'sqlite') { DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_payments_prevent_overpayment
            BEFORE INSERT ON payments
            FOR EACH ROW
            BEGIN
                DECLARE total_captured BIGINT;
                DECLARE order_total BIGINT;

                IF NEW.status = 'captured' THEN
                    SELECT COALESCE(SUM(amount), 0) INTO total_captured
                        FROM payments
                        WHERE order_id = NEW.order_id AND status = 'captured';

                    SELECT order_amount INTO order_total
                        FROM orders WHERE id = NEW.order_id;

                    IF (total_captured + NEW.amount) > order_total THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Total pembayaran captured melebihi order_amount -- ditolak oleh trigger overpayment guard.';
                    END IF;
                END IF;
            END
        SQL); }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_payments_prevent_overpayment');
        Schema::dropIfExists('payments');
    }
};
