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

        // 1. Tabel goods_received_notes (Header GRN)
        Schema::create('goods_received_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->string('grn_number', 64);
            $table->string('supplier_delivery_note_no', 100)->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('received_at');
            $table->unsignedBigInteger('received_by_user_id');
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by_user_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->unsignedBigInteger('reversal_of_grn_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamps();

            // Multi-tenant indexes & invariants
            $table->unique(['tenant_id', 'id'], 'grn_tenant_id_id_unique');
            $table->unique(['tenant_id', 'grn_number'], 'grn_tenant_number_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'grn_tenant_idempotency_unique');

            $table->index(['tenant_id', 'purchase_order_id', 'status'], 'grn_tenant_po_status_idx');
            $table->index(['tenant_id', 'store_id', 'received_at'], 'grn_tenant_store_received_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'store_id'], 'grn_store_composite_fk')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'supplier_id'], 'grn_supplier_composite_fk')
                ->references(['tenant_id', 'id'])->on('suppliers')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'purchase_order_id'], 'grn_po_composite_fk')
                ->references(['tenant_id', 'id'])->on('purchase_orders')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('received_by_user_id', 'grn_received_by_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('posted_by_user_id', 'grn_posted_by_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('reversed_by_user_id', 'grn_reversed_by_fk')
                ->references('id')->on('users')
                ->onDelete('restrict');

            $table->foreign('reversal_of_grn_id', 'grn_reversal_of_fk')
                ->references('id')->on('goods_received_notes')
                ->onDelete('restrict');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE goods_received_notes ADD CONSTRAINT chk_grn_status
                CHECK (status IN ('draft', 'posted', 'reversed', 'cancelled'))");
        }

        // 2. Tabel goods_received_note_lines (Baris Item GRN)
        Schema::create('goods_received_note_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('goods_received_note_id');
            $table->unsignedBigInteger('purchase_order_line_id');
            $table->unsignedInteger('line_number');
            $table->string('purchasable_type', 32);
            $table->unsignedBigInteger('purchasable_id');
            $table->string('uom', 30);
            $table->decimal('uom_conversion_factor', 18, 6)->default(1.000000);
            $table->decimal('received_qty', 18, 4)->default(0.0000);
            $table->decimal('accepted_qty', 18, 4)->default(0.0000);
            $table->decimal('rejected_qty', 18, 4)->default(0.0000);
            $table->decimal('damaged_qty', 18, 4)->default(0.0000);
            $table->string('damage_disposition', 50)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->decimal('base_accepted_qty', 18, 4)->default(0.0000);
            $table->decimal('unit_cost_snapshot', 18, 4)->default(0.0000);
            $table->decimal('allocated_landed_cost_per_unit', 18, 4)->default(0.0000);
            $table->decimal('effective_unit_cost', 18, 4)->default(0.0000);
            $table->string('batch_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('storage_location', 100)->nullable();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->unsignedBigInteger('ingredient_stock_movement_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Invariants & Indexes
            $table->unique(['tenant_id', 'id'], 'grnl_tenant_id_id_unique');
            $table->unique(['goods_received_note_id', 'line_number'], 'grnl_grn_line_unique');

            $table->index(['tenant_id', 'goods_received_note_id'], 'grnl_tenant_grn_idx');
            $table->index(['tenant_id', 'purchase_order_line_id'], 'grnl_tenant_pol_idx');
            $table->index(['tenant_id', 'purchasable_type', 'purchasable_id'], 'grnl_tenant_item_idx');

            // Composite Foreign Keys
            $table->foreign(['tenant_id', 'goods_received_note_id'], 'grnl_grn_composite_fk')
                ->references(['tenant_id', 'id'])->on('goods_received_notes')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign(['tenant_id', 'purchase_order_line_id'], 'grnl_pol_composite_fk')
                ->references(['tenant_id', 'id'])->on('purchase_order_lines')
                ->onDelete('restrict')->onUpdate('cascade');

            $table->foreign('stock_movement_id', 'grnl_stock_movement_fk')
                ->references('id')->on('stock_movements')
                ->onDelete('set null');

            $table->foreign('ingredient_stock_movement_id', 'grnl_ingredient_movement_fk')
                ->references('id')->on('ingredient_stock_movements')
                ->onDelete('set null');
        });

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE goods_received_note_lines ADD CONSTRAINT chk_grnl_qtys
                CHECK (received_qty >= 0 AND accepted_qty >= 0 AND rejected_qty >= 0 AND damaged_qty >= 0 AND base_accepted_qty >= 0)');

            DB::statement('ALTER TABLE goods_received_note_lines ADD CONSTRAINT chk_grnl_qty_balance
                CHECK (received_qty = accepted_qty + rejected_qty)');

            DB::statement('ALTER TABLE goods_received_note_lines ADD CONSTRAINT chk_grnl_uom_factor
                CHECK (uom_conversion_factor > 0)');

            // 3. Immutability Triggers
            DB::unprepared("
                CREATE TRIGGER trg_goods_received_notes_no_delete
                BEFORE DELETE ON goods_received_notes
                FOR EACH ROW
                BEGIN
                    IF OLD.status IN ('posted', 'reversed') THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'goods_received_notes yang sudah posted/reversed tidak boleh dihapus (DELETE dilarang).';
                    END IF;
                END;
            ");

            DB::unprepared("
                CREATE TRIGGER trg_goods_received_notes_immutable_update
                BEFORE UPDATE ON goods_received_notes
                FOR EACH ROW
                BEGIN
                    IF OLD.status = 'posted' AND NEW.status NOT IN ('posted', 'reversed') THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'goods_received_notes yang sudah posted hanya boleh diubah statusnya menjadi reversed.';
                    END IF;
                    IF OLD.status = 'reversed' THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'goods_received_notes yang sudah reversed bersifat immutable dan tidak boleh diubah.';
                    END IF;
                END;
            ");

            DB::unprepared("
                CREATE TRIGGER trg_grn_lines_no_delete
                BEFORE DELETE ON goods_received_note_lines
                FOR EACH ROW
                BEGIN
                    DECLARE grn_st VARCHAR(32);
                    SELECT status INTO grn_st FROM goods_received_notes WHERE id = OLD.goods_received_note_id LIMIT 1;
                    IF grn_st IN ('posted', 'reversed') THEN
                        SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Baris GRN yang sudah posted/reversed tidak boleh dihapus.';
                    END IF;
                END;
            ");
        }

        // 4. Seed Permissions Modul 5 untuk GRN
        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['permission_code' => 'purchasing.grn.view', 'description' => 'Melihat daftar dan rincian Goods Received Note (GRN)'],
                ['permission_code' => 'purchasing.grn.create', 'description' => 'Membuat draf Goods Received Note (GRN) penerimaan barang'],
                ['permission_code' => 'purchasing.grn.post', 'description' => 'Memposting GRN penerimaan barang ke buku besar inventori'],
                ['permission_code' => 'purchasing.grn.reverse', 'description' => 'Melakukan pembalikan (reversal) atas GRN yang sudah diposting'],
                ['permission_code' => 'purchasing.grn.cancel', 'description' => 'Membatalkan draf GRN penerimaan barang'],
                ['permission_code' => 'purchasing.po.view_prices', 'description' => 'Melihat harga satuan, diskon, dan nilai finansial PO/GRN'],
            ];

            foreach ($permissions as $p) {
                DB::table('permissions')->insertOrIgnore([
                    'permission_code' => $p['permission_code'],
                    'description' => $p['description'],
                ]);
            }
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_goods_received_notes_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_goods_received_notes_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_grn_lines_no_delete');
        }

        Schema::dropIfExists('goods_received_note_lines');
        Schema::dropIfExists('goods_received_notes');
    }
};
