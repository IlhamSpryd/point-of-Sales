<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE7] Enterprise Data Architecture Expansion — Modul Petty Cash / Cash Drawer Ledger | 2026-09-22
return new class extends Migration
{
    /**
     * Mencatat SETIAP pergerakan uang fisik di laci kasir SELAIN penjualan
     * tunai (yang sudah tercatat via orders.payment_method='cash'). Contoh:
     * setor modal tambahan, ambil kembalian receh dari brankas, bayar
     * kurir/ongkos kirim dadakan dari laci, setor sebagian ke bank di
     * tengah shift. TANPA tabel ini, ShiftManager::closeShift() hanya bisa
     * mencocokkan `opening_balance + cash_sales` terhadap uang fisik --
     * setiap penarikan/penyetoran non-penjualan akan tercatat sebagai
     * "selisih kas" palsu yang sebenarnya sah dan bisa dijelaskan.
     */
    public function up(): void
    {
        Schema::create('cash_drawer_movements', function (Blueprint $table) {
            $table->id();
            // WAJIB terikat ke satu shift -- pergerakan kas fisik yang
            // tidak terikat ke sesi kasir mana pun tidak bisa direkonsiliasi.
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->string('type', 10); // cash_in | cash_out
            $table->string('category', 50); // restock_change | petty_expense | owner_withdrawal | bank_deposit | correction
            $table->unsignedBigInteger('amount');
            $table->text('reason');
            $table->string('receipt_reference')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            // Governance layer opsional: penarikan besar (owner_withdrawal)
            // idealnya butuh approval Manager/Owner sebelum dianggap sah
            // dalam rekonsiliasi -- kolom ini disiapkan untuk itu; aturan
            // "wajib diisi untuk kategori tertentu" divalidasi di Service layer.
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['shift_id', 'type'], 'cash_drawer_movements_shift_type_index');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE cash_drawer_movements ADD CONSTRAINT chk_cdm_amount_positive CHECK (amount > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE cash_drawer_movements ADD CONSTRAINT chk_cdm_type_enum CHECK (type IN ('cash_in','cash_out'))");
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE cash_drawer_movements ADD CONSTRAINT chk_cdm_category_enum CHECK (category IN ('restock_change','petty_expense','owner_withdrawal','bank_deposit','correction'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_drawer_movements');
    }
};
