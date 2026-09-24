<?php

// [OMEGA-NODE1] PATCH FOR M-02: ModifierGroup/Modifier tidak memakai
// SoftDeletes, sementara modifiers.modifier_group_id dan
// order_item_modifiers.modifier_id keduanya cascadeOnDelete(). Admin
// menghapus SATU grup varian menghancurkan riwayat order_item_modifiers
// pada order yang SUDAH Paid/Completed -- pola identik DB-001 (cascade
// delete users menghancurkan riwayat orders) yang sudah pernah ditemukan
// dan diperbaiki di proyek ini untuk tabel lain, tapi belum untuk modifier.
// | 2026-09-24

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('modifiers', function (Blueprint $table) {
            $table->softDeletes();
        });

        // restrictOnDelete: jika suatu saat ada hard-delete/purge sungguhan
        // (mis. Modifier::withTrashed()->forceDelete()), DB akan MENOLAK
        // selama masih ada order_item_modifiers yang merujuknya -- pola
        // identik products.category_id/order_items.product_id di proyek ini.
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->dropForeign(['modifier_id']);
            $table->foreign('modifier_id')->references('id')->on('modifiers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->dropForeign(['modifier_id']);
            $table->foreign('modifier_id')->references('id')->on('modifiers')->cascadeOnDelete();
        });

        Schema::table('modifiers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
