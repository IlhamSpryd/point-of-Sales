<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provisioning_nonces')) {
            return;
        }
        Schema::create('provisioning_nonces', function (Blueprint $table) {
            $table->string('nonce', 64)->primary();
            $table->unsignedBigInteger('expires_at_epoch');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_nonces');
    }
};
