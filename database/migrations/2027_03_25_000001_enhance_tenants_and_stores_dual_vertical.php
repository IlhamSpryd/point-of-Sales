<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tenants table enhancements
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'primary_industry')) {
                $table->string('primary_industry', 32)->default('hybrid')->after('country_code');
            }
            if (! Schema::hasColumn('tenants', 'business_subtype')) {
                $table->string('business_subtype', 50)->default('cafe')->after('primary_industry');
            }
        });

        // 2. Stores table enhancements
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'store_vertical')) {
                $table->string('store_vertical', 32)->nullable()->default('hybrid')->after('timezone');
            }
            if (! Schema::hasColumn('stores', 'default_channel')) {
                $table->string('default_channel', 30)->default('dine_in')->after('store_vertical');
            }
        });

        // Ensure default fallback values for existing null/blank rows
        if (Schema::hasColumn('tenants', 'primary_industry')) {
            DB::table('tenants')
                ->whereNull('primary_industry')
                ->orWhere('primary_industry', '')
                ->update(['primary_industry' => 'hybrid']);
        }

        if (Schema::hasColumn('stores', 'store_vertical')) {
            DB::table('stores')
                ->whereNull('store_vertical')
                ->orWhere('store_vertical', '')
                ->update(['store_vertical' => 'hybrid']);
        }
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'store_vertical')) {
                $table->dropColumn('store_vertical');
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'business_subtype')) {
                $table->dropColumn('business_subtype');
            }
        });
    }
};

