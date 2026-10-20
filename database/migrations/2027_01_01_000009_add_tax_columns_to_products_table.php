<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 2 — Modul 3
 * Menambahkan tax_class_id dan is_tax_inclusive pada tabel products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'tax_class_id')) {
                $table->unsignedBigInteger('tax_class_id')->nullable()->after('standard_cost');
            }
            if (! Schema::hasColumn('products', 'is_tax_inclusive')) {
                $table->boolean('is_tax_inclusive')->default(false)->after('tax_class_id');
            }
        });

        // Composite FK ke tax_classes
        $fkList = array_map(fn ($r) => $r->CONSTRAINT_NAME, DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'"));
        Schema::table('products', function (Blueprint $table) use ($fkList) {
            if (! in_array('products_tenant_tax_class_composite_foreign', $fkList, true)) {
                $table->foreign(['tenant_id', 'tax_class_id'], 'products_tenant_tax_class_composite_foreign')
                    ->references(['tenant_id', 'id'])->on('tax_classes')
                    ->onDelete('RESTRICT');
            }
        });

        // Index
        $idxList = array_map(fn ($r) => $r->Key_name, DB::select('SHOW INDEX FROM products'));
        Schema::table('products', function (Blueprint $table) use ($idxList) {
            if (! in_array('products_tenant_tax_class_idx', $idxList, true)) {
                $table->index(['tenant_id', 'tax_class_id'], 'products_tenant_tax_class_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign('products_tenant_tax_class_composite_foreign');
            $table->dropIndex('products_tenant_tax_class_idx');
            $table->dropColumn(['tax_class_id', 'is_tax_inclusive']);
        });
    }
};
