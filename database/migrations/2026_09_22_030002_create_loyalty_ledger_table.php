<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE7] Enterprise Data Architecture Expansion — Modul CRM & Loyalty | 2026-09-22
return new class extends Migration
{
    /**
     * Ledger poin APPEND-ONLY, hash-chained per pelanggan. Ini adalah
     * SATU-SATUNYA sumber kebenaran untuk saldo poin -- `current_points`
     * di `customer_loyalty_accounts` (migrasi berikutnya) HANYALAH cache
     * materialized untuk lookup cepat, disinkronkan OTOMATIS oleh trigger
     * AFTER INSERT, BUKAN ditulis manual oleh aplikasi.
     *
     * prev_hash/entry_hash membentuk rantai integritas ala blockchain
     * sederhana: mengubah SATU baris lama secara diam-diam (mis. akses
     * langsung ke database oleh pihak dalam) akan merusak entry_hash baris
     * itu DAN seluruh baris setelahnya untuk pelanggan yang sama --
     * terdeteksi lewat job verifikasi berkala yang menghitung ulang rantai.
     * Kedua kolom hash DIISI OTOMATIS oleh trigger di bawah; aplikasi TIDAK
     * PERNAH mengirim nilainya secara manual.
     */
    public function up(): void
    {
        Schema::create('loyalty_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('type', 20); // earn | redeem | expire | adjustment | bonus | reversal
            $table->bigInteger('points'); // signed: earn/bonus positif, redeem/expire negatif
            $table->unsignedBigInteger('balance_after'); // snapshot saldo setelah entry ini -- audit cepat tanpa replay penuh
            $table->string('reference')->nullable();
            $table->char('prev_hash', 64)->nullable(); // hash entry SEBELUMNYA milik pelanggan yang sama, null untuk entry pertama
            $table->char('entry_hash', 64)->unique(); // SHA-256 rantai, diisi trigger
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent(); // TANPA updated_at -- ledger tidak pernah diubah

            $table->index(['customer_id', 'id'], 'loyalty_ledger_customer_chain_index');
            $table->index('order_id', 'loyalty_ledger_order_index');
        });

        if (DB::getDriverName() !== 'sqlite') { DB::statement("ALTER TABLE loyalty_ledger ADD CONSTRAINT chk_loyalty_ledger_type_enum CHECK (type IN ('earn','redeem','expire','adjustment','bonus','reversal'))"); }

        if (DB::getDriverName() !== 'sqlite') { DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_loyalty_ledger_hash_chain
            BEFORE INSERT ON loyalty_ledger
            FOR EACH ROW
            BEGIN
                DECLARE last_hash CHAR(64);

                SELECT entry_hash INTO last_hash
                    FROM loyalty_ledger
                    WHERE customer_id = NEW.customer_id
                    ORDER BY id DESC
                    LIMIT 1;

                SET NEW.prev_hash = last_hash;
                SET NEW.entry_hash = SHA2(CONCAT_WS('|',
                    NEW.customer_id, IFNULL(NEW.order_id, ''), NEW.type, NEW.points,
                    NEW.balance_after, IFNULL(last_hash, ''), NOW()
                ), 256);
            END
        SQL); }

        if (DB::getDriverName() !== 'sqlite') { DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_loyalty_ledger_no_update
            BEFORE UPDATE ON loyalty_ledger
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'loyalty_ledger bersifat append-only: UPDATE dilarang.';
            END
        SQL); }

        if (DB::getDriverName() !== 'sqlite') { DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_loyalty_ledger_no_delete
            BEFORE DELETE ON loyalty_ledger
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'loyalty_ledger bersifat append-only: DELETE dilarang.';
            END
        SQL); }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_hash_chain');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_no_delete');
        Schema::dropIfExists('loyalty_ledger');
    }
};
