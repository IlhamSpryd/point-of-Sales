<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TR-005 / DEC-005: Penegakan Kontrol Laci Kas Ketat & Otorisasi PIN Selisih Kas.
     * Menambahkan variance_approved_by dan variance_reason pada shifts dengan composite foreign key.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('variance_approved_by')->nullable()->after('cash_difference');
            $table->string('variance_reason', 255)->nullable()->after('variance_approved_by');

            $table->foreign(['tenant_id', 'variance_approved_by'], 'fk_shifts_variance_approved_by_tenant_comp')
                ->references(['tenant_id', 'id'])->on('users')
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign('fk_shifts_variance_approved_by_tenant_comp');
            $table->dropColumn(['variance_approved_by', 'variance_reason']);
        });
    }
};
