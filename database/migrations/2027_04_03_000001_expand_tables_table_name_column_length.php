<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * TASK-003: Expand tables.table_name column from VARCHAR(10) to VARCHAR(100)
     * to prevent SQL 1406 errors when restaurant tables have descriptive names.
     */
    public function up(): void
    {
        if (Schema::hasTable('tables') && Schema::hasColumn('tables', 'table_name')) {
            if (DB::getDriverName() === 'sqlite') {
                Schema::table('tables', function (Blueprint $table) {
                    $table->string('table_name', 100)->change();
                });
            } else {
                DB::statement('ALTER TABLE `tables` MODIFY `table_name` VARCHAR(100) NOT NULL');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tables') && Schema::hasColumn('tables', 'table_name')) {
            if (DB::getDriverName() === 'sqlite') {
                Schema::table('tables', function (Blueprint $table) {
                    $table->string('table_name', 10)->change();
                });
            } else {
                DB::statement('ALTER TABLE `tables` MODIFY `table_name` VARCHAR(10) NOT NULL');
            }
        }
    }
};
