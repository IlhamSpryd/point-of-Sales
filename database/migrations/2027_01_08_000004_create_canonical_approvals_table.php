<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('approvals')) {
            Schema::create('approvals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('requester_user_id');
                $table->unsignedBigInteger('approver_user_id');
                $table->string('action_code', 50); // MANUAL_DISCOUNT, OPEN_PRICE, VOID_ORDER
                $table->string('target_entity_type', 100)->nullable();
                $table->unsignedBigInteger('target_entity_id')->nullable();
                $table->decimal('amount', 15, 2)->default(0.00);
                $table->string('reason', 255)->nullable();
                $table->string('status', 20)->default('APPROVED'); // APPROVED, REJECTED, CONSUMED, VOIDED
                $table->timestamp('consumed_at')->nullable();
                $table->unsignedBigInteger('consumed_by_order_id')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'store_id', 'action_code', 'status'], 'idx_approvals_tenant_store_act_stat');
                $table->index(['tenant_id', 'requester_user_id'], 'idx_approvals_tenant_requester');
                $table->index(['tenant_id', 'approver_user_id'], 'idx_approvals_tenant_approver');
                $table->index(['consumed_by_order_id'], 'idx_approvals_order_id');
            });
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_approvals_before_update;");
            DB::unprepared("
                CREATE TRIGGER trg_approvals_before_update BEFORE UPDATE ON approvals
                FOR EACH ROW
                BEGIN
                    IF OLD.status = 'CONSUMED' THEN
                        IF NEW.status != 'CONSUMED'
                           OR OLD.consumed_at != NEW.consumed_at
                           OR OLD.consumed_by_order_id != NEW.consumed_by_order_id
                           OR OLD.amount != NEW.amount
                           OR OLD.requester_user_id != NEW.requester_user_id
                           OR OLD.approver_user_id != NEW.approver_user_id THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approval record is immutable once CONSUMED.';
                        END IF;
                    END IF;
                END;
            ");

            DB::unprepared("DROP TRIGGER IF EXISTS trg_approvals_before_delete;");
            DB::unprepared("
                CREATE TRIGGER trg_approvals_before_delete BEFORE DELETE ON approvals
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approvals are immutable audit records and cannot be deleted.';
                END;
            ");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_approvals_before_update;");
            DB::unprepared("DROP TRIGGER IF EXISTS trg_approvals_before_delete;");
        }

        Schema::dropIfExists('approvals');
    }
};
