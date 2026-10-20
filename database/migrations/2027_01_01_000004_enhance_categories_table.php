<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inkremen 1 — Modul 3
 * Perluasan master categories: parent_id (hierarki), description, sort_order, is_active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable()->after('tenant_id');
            }
            if (! Schema::hasColumn('categories', 'description')) {
                $table->text('description')->nullable()->after('category_name');
            }
            if (! Schema::hasColumn('categories', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('description');
            }
            if (! Schema::hasColumn('categories', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('sort_order');
            }
        });

        // Composite FK & Indexes
        $fkList = array_map(fn ($r) => $r->CONSTRAINT_NAME, DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories'"));
        if (! in_array('categories_tenant_parent_composite_foreign', $fkList, true)) {
            Schema::table('categories', function (Blueprint $table) {
                $table->foreign(['tenant_id', 'parent_id'], 'categories_tenant_parent_composite_foreign')
                    ->references(['tenant_id', 'id'])->on('categories')
                    ->onDelete('RESTRICT');
            });
        }

        $idxList = array_map(fn ($r) => $r->Key_name, DB::select('SHOW INDEX FROM categories'));
        Schema::table('categories', function (Blueprint $table) use ($idxList) {
            if (! in_array('categories_tenant_parent_sort_idx', $idxList, true)) {
                $table->index(['tenant_id', 'parent_id', 'sort_order'], 'categories_tenant_parent_sort_idx');
            }
            if (! in_array('categories_tenant_is_active_idx', $idxList, true)) {
                $table->index(['tenant_id', 'is_active'], 'categories_tenant_is_active_idx');
            }
        });

        // Trigger to enforce parent_id <> id (MariaDB/MySQL does not allow AUTO_INCREMENT in CHECK constraint)
        DB::unprepared("
            CREATE TRIGGER IF NOT EXISTS `trg_categories_check_parent_before_update`
            BEFORE UPDATE ON `categories`
            FOR EACH ROW
            BEGIN
                IF NEW.parent_id IS NOT NULL AND NEW.parent_id = NEW.id THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Category cannot be its own parent.';
                END IF;
            END;
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `trg_categories_check_parent_before_update`');
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign('categories_tenant_parent_composite_foreign');
            $table->dropIndex('categories_tenant_parent_sort_idx');
            $table->dropIndex('categories_tenant_is_active_idx');
            $table->dropColumn(['parent_id', 'description', 'sort_order', 'is_active']);
        });
    }
};
