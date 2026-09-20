<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_order_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider'); // grabfood, gofood
            $table->string('external_order_id');
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->json('payload');
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Constraint level database untuk Idempotency (Cegah Duplikasi Pesanan)
            $table->unique(['provider', 'external_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_order_logs');
    }
};
