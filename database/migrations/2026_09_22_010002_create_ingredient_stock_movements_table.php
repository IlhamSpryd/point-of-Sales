<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE7] Enterprise Data Architecture Expansion — Modul Bill of Materials | 2026-09-22
return new class extends Migration
{
    /**
     * Ledger APPEND-ONLY untuk seluruh mutasi stok bahan baku (potongan
     * otomatis saat order dibayar, penerimaan dari supplier, waste/rusak,
     * koreksi manual Inventory). Pola identik dengan `stock_movements`
     * (Node 1, lihat migrasi 2026_09_21_080000) tetapi untuk `ingredients`,
     * bukan `products` -- SATU order bisa memotong BANYAK ingredient
     * sekaligus lewat BOM, jadi ledger ini WAJIB granular per-ingredient.
     *
     * quantity BERTANDA (signed): negatif = konsumsi/keluar, positif =
     * penerimaan/masuk. Baris HANYA di-INSERT, tidak pernah di-UPDATE atau
     * DIHAPUS -- ditegakkan oleh trigger imutabilitas di bawah.
     */
    public function up(): void
    {
        Schema::create('ingredient_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->restrictOnDelete();
            $table->string('type', 30); // sale_deduction | purchase_receipt | waste | adjustment | restore_compensation
            $table->decimal('quantity', 14, 4); // signed -- lihat catatan di atas
            $table->decimal('unit_cost', 14, 2)->nullable(); // dibekukan pada harga beli saat itu, agar COGS historis akurat
            $table->text('reason')->nullable();
            // Idempotency lapis ketiga -- pola identik dengan
            // stock_movements.idempotency_key milik Node 1: menjamin
            // "exactly-once" walau compensator dipanggil berkali-kali oleh
            // retry webhook yang sama.
            $table->string('idempotency_key')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent(); // TANPA updated_at -- baris ini tidak pernah diubah

            $table->index(['ingredient_id', 'created_at'], 'ingredient_stock_movements_ingredient_index');
            $table->index(['order_id', 'type'], 'ingredient_stock_movements_order_type_index');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE ingredient_stock_movements ADD CONSTRAINT chk_ism_type_enum CHECK (type IN ('sale_deduction','purchase_receipt','waste','adjustment','restore_compensation'))");
        }

        // Imutabilitas DITEGAKKAN DI DATABASE, bukan hanya konvensi kode:
        // siapa pun yang mencoba UPDATE atau DELETE baris ledger ini --
        // termasuk query raw/manual dari luar Eloquent -- ditolak dengan
        // error eksplisit. Ini melengkapi (bukan menggantikan) disiplin
        // "jangan pernah update ledger" di level aplikasi.
        if (DB::getDriverName() !== 'sqlite') {
            DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_ingredient_stock_movements_no_update
            BEFORE UPDATE ON ingredient_stock_movements
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'ingredient_stock_movements bersifat append-only: UPDATE dilarang.';
            END
        SQL);
        }

        if (DB::getDriverName() !== 'sqlite') {
            DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_ingredient_stock_movements_no_delete
            BEFORE DELETE ON ingredient_stock_movements
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'ingredient_stock_movements bersifat append-only: DELETE dilarang.';
            END
        SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ingredient_stock_movements_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ingredient_stock_movements_no_delete');
        Schema::dropIfExists('ingredient_stock_movements');
    }
};
