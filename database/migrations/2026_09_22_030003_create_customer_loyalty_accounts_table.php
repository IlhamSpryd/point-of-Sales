<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE7] Enterprise Data Architecture Expansion — Modul CRM & Loyalty | 2026-09-22
return new class extends Migration
{
    /**
     * Cache materialized saldo poin. TIDAK PERNAH ditulis langsung oleh
     * kode aplikasi -- hanya trigger AFTER INSERT pada loyalty_ledger yang
     * boleh meng-upsert baris ini, menjaga SATU-SATUNYA jalur penulisan
     * agar cache tidak pernah drift dari ledger (sumber kebenaran).
     *
     * PERINGATAN KONKURENSI (baca narasi resiliency di jawaban utama):
     * trigger ini menjaga cache tetap SINKRON setelah fakta, tapi TIDAK
     * mencegah dua transaksi earn/redeem BERSAMAAN pada pelanggan yang
     * sama menghitung `balance_after` yang salah di level aplikasi --
     * Service layer WAJIB mengunci baris ini (lockForUpdate) SEBELUM
     * menghitung balance_after dan meng-INSERT ke loyalty_ledger.
     */
    public function up(): void
    {
        Schema::create('customer_loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('current_points')->default(0);
            $table->unsignedBigInteger('lifetime_points_earned')->default(0);
            $table->foreignId('current_tier_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'sqlite') { DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_loyalty_ledger_sync_account
            AFTER INSERT ON loyalty_ledger
            FOR EACH ROW
            BEGIN
                INSERT INTO customer_loyalty_accounts (customer_id, current_points, lifetime_points_earned, created_at, updated_at)
                VALUES (
                    NEW.customer_id,
                    NEW.balance_after,
                    GREATEST(NEW.points, 0),
                    NOW(),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    current_points = NEW.balance_after,
                    lifetime_points_earned = lifetime_points_earned + GREATEST(NEW.points, 0),
                    updated_at = NOW();
            END
        SQL); }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_loyalty_ledger_sync_account');
        Schema::dropIfExists('customer_loyalty_accounts');
    }
};
