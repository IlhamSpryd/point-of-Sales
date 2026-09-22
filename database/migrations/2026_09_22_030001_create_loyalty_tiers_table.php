<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// [OMEGA-NODE7] Enterprise Data Architecture Expansion — Modul CRM & Loyalty | 2026-09-22
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('tier_code')->unique()->nullable();
            $table->string('name'); // Bronze / Silver / Gold
            $table->unsignedBigInteger('min_points')->default(0);
            $table->decimal('points_multiplier', 4, 2)->unsigned()->default(1.00);
            $table->json('benefits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('min_points', 'loyalty_tiers_min_points_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};
