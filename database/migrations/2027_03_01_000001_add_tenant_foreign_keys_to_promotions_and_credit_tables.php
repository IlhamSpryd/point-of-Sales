<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daftar 10 tabel target multi-tenant untuk penambahan foreign key ke tenants(id).
     *
     * @var list<string>
     */
    protected array $tables = [
        'customer_credit_accounts',
        'customer_credit_logs',
        'promotion_campaigns',
        'promotion_rules',
        'promotion_rewards',
        'promotion_reservations',
        'promotion_store_mappings',
        'approvals',
        'accounting_receivable_outboxes',
        'accounting_payable_outbox',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! DB::pretending()) {
                if (! Schema::hasTable($tableName)) {
                    continue;
                }

                if ($this->hasTenantForeignKey($tableName)) {
                    continue;
                }
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('restrict');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! DB::pretending()) {
                if (! Schema::hasTable($tableName)) {
                    continue;
                }

                if (! $this->hasTenantForeignKey($tableName)) {
                    continue;
                }
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
            });
        }
    }

    /**
     * Memeriksa apakah foreign key dari tenant_id ke tenants(id) sudah ada.
     */
    protected function hasTenantForeignKey(string $tableName): bool
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return false;
        }

        $fk = DB::select(
            "SELECT CONSTRAINT_NAME 
             FROM information_schema.KEY_COLUMN_USAGE 
             WHERE TABLE_SCHEMA = DATABASE() 
               AND TABLE_NAME = ? 
               AND COLUMN_NAME = 'tenant_id' 
               AND REFERENCED_TABLE_NAME = 'tenants'",
            [$tableName]
        );

        return ! empty($fk);
    }
};
