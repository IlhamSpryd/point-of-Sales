<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE5] Jejak audit: log webhook mana yang menghasilkan order mana | 2026-09-23
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_order_logs', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('status')
                ->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channel_order_logs', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropColumn('order_id');
        });
    }
};
