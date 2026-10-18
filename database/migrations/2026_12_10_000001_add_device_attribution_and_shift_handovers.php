<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Increment 1: Device Attribution, Shift Uniqueness per Device, & Shift Handovers.
     * Enforces INV-01, INV-03, INV-04, INV-05.
     */
    public function up(): void
    {
        // 1. devices: Add unique index (tenant_id, store_id, id) for composite FK targeting
        Schema::table('devices', function (Blueprint $table) {
            $table->unique(['tenant_id', 'store_id', 'id'], 'devices_tenant_store_id_unique');
        });

        // 2. shifts: Add unique index (tenant_id, store_id, id) for composite FK targeting
        Schema::table('shifts', function (Blueprint $table) {
            $table->unique(['tenant_id', 'store_id', 'id'], 'shifts_tenant_store_id_unique');
        });

        // 3. shifts: Add device attribution, active_cashier, close audit
        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable()->after('store_id');
            $table->unsignedBigInteger('active_cashier_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('closed_by_user_id')->nullable()->after('closed_at');
            $table->string('close_type', 32)->default('normal')->after('closed_by_user_id');
            $table->text('close_reason')->nullable()->after('close_type');
        });

        // Backfill active_cashier_id for existing shifts
        DB::statement('UPDATE shifts SET active_cashier_id = user_id WHERE active_cashier_id IS NULL');

        // Add virtual generated column open_device_guard and unique index on shifts
        DB::statement("ALTER TABLE shifts ADD COLUMN open_device_guard BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('open', 'handover_pending', 'closing') AND device_id IS NOT NULL THEN device_id ELSE NULL END) VIRTUAL");
        DB::statement('ALTER TABLE shifts ADD UNIQUE INDEX shifts_one_open_per_device_unique (tenant_id, store_id, open_device_guard)');

        // Update open_shift_lock_key to use COALESCE(active_cashier_id, user_id)
        DB::statement("ALTER TABLE shifts MODIFY COLUMN open_shift_lock_key BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('open', 'handover_pending', 'closing') THEN COALESCE(active_cashier_id, user_id) ELSE NULL END) VIRTUAL");

        // Add composite foreign keys and constraints to shifts
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'store_id', 'device_id'], 'shifts_device_id_composite_foreign')
                ->references(['tenant_id', 'store_id', 'id'])->on('devices')
                ->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign(['tenant_id', 'active_cashier_id'], 'shifts_active_cashier_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->cascadeOnUpdate();

            $table->foreign(['tenant_id', 'closed_by_user_id'], 'shifts_closed_by_user_id_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->cascadeOnUpdate();
        });

        DB::statement("ALTER TABLE shifts ADD CONSTRAINT chk_shifts_close_type CHECK (close_type IN ('normal', 'force_closed'))");

        // 4. Create shift_handovers table
        Schema::create('shift_handovers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('from_user_id');
            $table->unsignedBigInteger('to_user_id');
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->unsignedBigInteger('expected_cash_at_handover');
            $table->unsignedBigInteger('counted_cash_at_handover');
            $table->bigInteger('discrepancy_amount');
            $table->string('status', 24)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('initiated_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'shift_handovers_tenant_id_id_unique');
            $table->index(['tenant_id', 'store_id', 'shift_id'], 'shift_handovers_shift_index');

            $table->foreign(['tenant_id', 'store_id', 'shift_id'], 'shift_handovers_shift_composite_foreign')
                ->references(['tenant_id', 'store_id', 'id'])->on('shifts')
                ->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign(['tenant_id', 'store_id', 'device_id'], 'shift_handovers_device_composite_foreign')
                ->references(['tenant_id', 'store_id', 'id'])->on('devices')
                ->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign(['tenant_id', 'from_user_id'], 'shift_handovers_from_user_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->cascadeOnUpdate();

            $table->foreign(['tenant_id', 'to_user_id'], 'shift_handovers_to_user_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->cascadeOnUpdate();

            $table->foreign(['tenant_id', 'approved_by_user_id'], 'shift_handovers_approved_by_composite_foreign')
                ->references(['tenant_id', 'id'])->on('users')
                ->cascadeOnUpdate();
        });

        DB::statement("ALTER TABLE shift_handovers ADD CONSTRAINT chk_shift_handovers_status CHECK (status IN ('pending', 'accepted', 'cancelled', 'rejected'))");

        // 5. orders table: Add origin_channel, source_reference, created_by_device_id
        Schema::table('orders', function (Blueprint $table) {
            $table->string('origin_channel', 32)->default('pos_cashier')->after('order_type');
            $table->string('source_reference', 128)->nullable()->after('origin_channel');
            $table->unsignedBigInteger('created_by_device_id')->nullable()->after('source_reference');

            $table->foreign(['tenant_id', 'store_id', 'created_by_device_id'], 'orders_created_by_device_composite_foreign')
                ->references(['tenant_id', 'store_id', 'id'])->on('devices')
                ->restrictOnDelete()->cascadeOnUpdate();
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_origin_channel CHECK (origin_channel IN ('pos_cashier', 'qr_table', 'gofood', 'grabfood', 'other_external'))");

        // 6. payments table: Add device_id and shift_id with composite FKs
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable()->after('store_id');
            $table->unsignedBigInteger('shift_id')->nullable()->after('order_id');

            $table->foreign(['tenant_id', 'store_id', 'device_id'], 'payments_device_id_composite_foreign')
                ->references(['tenant_id', 'store_id', 'id'])->on('devices')
                ->restrictOnDelete()->cascadeOnUpdate();

            $table->foreign(['tenant_id', 'store_id', 'shift_id'], 'payments_shift_id_composite_foreign')
                ->references(['tenant_id', 'store_id', 'id'])->on('shifts')
                ->restrictOnDelete()->cascadeOnUpdate();
        });

        // 7. cash_drawer_movements table: Add device_id with composite FK
        Schema::table('cash_drawer_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable()->after('store_id');

            $table->foreign(['tenant_id', 'store_id', 'device_id'], 'cash_drawer_movements_device_id_composite_foreign')
                ->references(['tenant_id', 'store_id', 'id'])->on('devices')
                ->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        // 7. cash_drawer_movements
        Schema::table('cash_drawer_movements', function (Blueprint $table) {
            $table->dropForeign('cash_drawer_movements_device_id_composite_foreign');
            $table->dropColumn('device_id');
        });

        // 6. payments
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign('payments_shift_id_composite_foreign');
            $table->dropForeign('payments_device_id_composite_foreign');
            $table->dropColumn(['shift_id', 'device_id']);
        });

        // 5. orders
        DB::statement('ALTER TABLE orders DROP CONSTRAINT chk_orders_origin_channel');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_created_by_device_composite_foreign');
            $table->dropColumn(['created_by_device_id', 'source_reference', 'origin_channel']);
        });

        // 4. shift_handovers
        Schema::dropIfExists('shift_handovers');

        // 3. shifts
        DB::statement('ALTER TABLE shifts DROP CONSTRAINT chk_shifts_close_type');
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign('shifts_closed_by_user_id_composite_foreign');
            $table->dropForeign('shifts_active_cashier_id_composite_foreign');
            $table->dropForeign('shifts_device_id_composite_foreign');
            $table->dropUnique('shifts_one_open_per_device_unique');
            $table->dropColumn('open_device_guard');
        });

        DB::statement("ALTER TABLE shifts MODIFY COLUMN open_shift_lock_key BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN user_id ELSE NULL END) VIRTUAL");

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['close_reason', 'close_type', 'closed_by_user_id', 'active_cashier_id', 'device_id']);
            $table->dropUnique('shifts_tenant_store_id_unique');
        });

        // 1. devices
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique('devices_tenant_store_id_unique');
        });
    }
};
