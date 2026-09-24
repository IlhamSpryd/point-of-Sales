<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')->whereNull('idempotency_key')->orderBy('id')
            ->chunkById(500, function ($orders) {
                foreach ($orders as $order) {
                    DB::table('orders')->where('id', $order->id)
                        ->update(['idempotency_key' => (string) Str::uuid()]);
                }
            });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key', 36)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key', 36)->nullable()->change();
        });
    }
};
