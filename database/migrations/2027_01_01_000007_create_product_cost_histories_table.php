<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 2 — Modul 3
 * Riwayat Biaya Standar / Referensi Append-Only (product_cost_histories).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_cost_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('costing_method', 32)->default('standard');
            $table->decimal('old_cost', 15, 2)->default(0.00);
            $table->decimal('new_cost', 15, 2)->default(0.00);
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->dateTime('effective_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id', 'pch_tenant_id_foreign')
                ->references('id')->on('tenants')
                ->onDelete('CASCADE');

            // Composite FK ke products
            $table->foreign(['tenant_id', 'product_id'], 'pch_product_composite_foreign')
                ->references(['tenant_id', 'id'])->on('products')
                ->onDelete('CASCADE');

            // Composite FK ke stores
            $table->foreign(['tenant_id', 'store_id'], 'pch_store_composite_foreign')
                ->references(['tenant_id', 'id'])->on('stores')
                ->onDelete('RESTRICT');

            $table->foreign('changed_by', 'pch_changed_by_foreign')
                ->references('id')->on('users')
                ->onDelete('SET NULL');

            $table->index(['tenant_id', 'product_id', 'effective_at'], 'pch_tenant_product_effective_idx');
        });

        // Trigger Immutabilitas: Append-only
        DB::unprepared("
            CREATE TRIGGER trg_cost_histories_before_update BEFORE UPDATE ON product_cost_histories
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'product_cost_histories bersifat append-only: UPDATE dilarang.';
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER trg_cost_histories_before_delete BEFORE DELETE ON product_cost_histories
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'product_cost_histories bersifat append-only: DELETE dilarang.';
            END;
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cost_histories_before_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cost_histories_before_update');
        Schema::dropIfExists('product_cost_histories');
    }
};
