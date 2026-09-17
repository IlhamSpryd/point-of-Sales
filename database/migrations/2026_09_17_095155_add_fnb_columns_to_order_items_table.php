<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('options');

            $table->string('preparation_status', 20)
                ->default('pending')
                ->after('notes');

            // Barista/KDS akan polling per-ITEM (satu meja bisa punya item
            // berbeda status: minuman 'ready', roti masih 'brewing') ->
            // index wajib di level baris item, bukan cukup di level order.
            $table->index('preparation_status', 'order_items_preparation_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_preparation_status_index');
            $table->dropColumn(['notes', 'preparation_status']);
        });
    }
};
