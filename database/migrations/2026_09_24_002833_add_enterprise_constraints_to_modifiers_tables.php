<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->integer('min_select')->default(1);
            $table->integer('max_select')->nullable();
        });

        Schema::table('modifiers', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        Schema::table('modifier_ingredients', function (Blueprint $table) {
            $table->unique(['modifier_id', 'ingredient_id']);
        });

        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->unique(['order_item_id', 'modifier_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->dropUnique(['order_item_id', 'modifier_id']);
        });

        Schema::table('modifier_ingredients', function (Blueprint $table) {
            $table->dropUnique(['modifier_id', 'ingredient_id']);
        });

        Schema::table('modifiers', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->dropColumn(['min_select', 'max_select']);
        });
    }
};
