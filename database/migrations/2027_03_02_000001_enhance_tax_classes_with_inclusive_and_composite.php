<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_classes', function (Blueprint $table) {
            if (! Schema::hasColumn('tax_classes', 'is_inclusive')) {
                $table->boolean('is_inclusive')->default(false)->after('rate');
            }
            if (! Schema::hasColumn('tax_classes', 'composite_rates')) {
                $table->json('composite_rates')->nullable()->after('is_inclusive');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tax_classes', function (Blueprint $table) {
            $table->dropColumn(['is_inclusive', 'composite_rates']);
        });
    }
};
