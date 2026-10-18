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
     * Increment 4: Discount Redemptions, Item-Level Discount, and Server-Backed Parked Carts.
     */
    public function up(): void
    {
        // 1. Tambah kolom kuota & stacking pada discounts jika belum ada
        if (Schema::hasTable('discounts')) {
            Schema::table('discounts', function (Blueprint $table) {
                if (! Schema::hasColumn('discounts', 'usage_limit')) {
                    $table->unsignedInteger('usage_limit')->nullable()->after('min_purchase_amount');
                }
                if (! Schema::hasColumn('discounts', 'is_stackable')) {
                    $table->boolean('is_stackable')->default(false)->after('usage_limit');
                }
                if (! Schema::hasColumn('discounts', 'scope')) {
                    $table->string('scope', 32)->default('order')->after('is_stackable');
                }
            });
        }

        // 2. Tambah kolom diskon item & alokasi proporsional pada order_items
        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (! Schema::hasColumn('order_items', 'item_discount_id')) {
                    $table->foreignId('item_discount_id')->nullable()->after('store_id')
                        ->constrained('discounts')->nullOnDelete();
                }
                if (! Schema::hasColumn('order_items', 'item_discount_amount')) {
                    $table->decimal('item_discount_amount', 15, 2)->default(0.00)->after('item_discount_id');
                }
                if (! Schema::hasColumn('order_items', 'allocated_order_discount_amount')) {
                    $table->decimal('allocated_order_discount_amount', 15, 2)->default(0.00)->after('item_discount_amount');
                }
                if (! Schema::hasColumn('order_items', 'net_line_total')) {
                    $table->decimal('net_line_total', 15, 2)->default(0.00)->after('allocated_order_discount_amount');
                }
            });
        }

        // 3. Tabel discount_redemptions
        if (! Schema::hasTable('discount_redemptions')) {
            Schema::create('discount_redemptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('discount_id');
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedBigInteger('redeemed_by_user_id')->nullable();
                $table->string('discount_code_snapshot', 64)->nullable();
                $table->decimal('amount_discounted', 15, 2);
                $table->string('status', 32)->default('applied');
                $table->timestamp('redeemed_at');
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'order_id', 'discount_id'], 'uq_discount_redemptions_order_discount');
                $table->index(['tenant_id', 'discount_id', 'status'], 'idx_dr_tenant_discount_status');

                $table->foreign(['tenant_id', 'store_id'], 'fk_dr_store')
                    ->references(['tenant_id', 'id'])->on('stores')->onDelete('restrict');
                $table->foreign(['tenant_id', 'discount_id'], 'fk_dr_discount')
                    ->references(['tenant_id', 'id'])->on('discounts')->onDelete('restrict');
                $table->foreign(['tenant_id', 'order_id'], 'fk_dr_order')
                    ->references(['tenant_id', 'id'])->on('orders')->onDelete('restrict');
                $table->foreign('customer_id', 'fk_dr_customer')
                    ->references('id')->on('customers')->nullOnDelete();
                $table->foreign('redeemed_by_user_id', 'fk_dr_user')
                    ->references('id')->on('users')->nullOnDelete();
            });

            DB::statement("ALTER TABLE discount_redemptions ADD CONSTRAINT chk_dr_status_enum CHECK (status IN ('applied', 'voided_reversed'))");
        }

        // 4. Tabel parked_carts
        if (! Schema::hasTable('parked_carts')) {
            Schema::create('parked_carts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('device_id');
                $table->unsignedBigInteger('shift_id');
                $table->unsignedBigInteger('parked_by_user_id');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedBigInteger('table_id')->nullable();
                $table->string('hold_reference', 64);
                $table->string('customer_label', 128)->nullable();
                $table->string('order_type', 32)->default('dine_in');
                $table->json('cart_payload');
                $table->unsignedBigInteger('discount_id')->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 32)->default('parked');
                $table->timestamp('expires_at')->nullable();
                $table->unsignedBigInteger('resumed_by_user_id')->nullable();
                $table->unsignedBigInteger('converted_order_id')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'hold_reference'], 'uq_parked_carts_hold_ref');
                $table->index(['tenant_id', 'store_id', 'status'], 'idx_parked_carts_tenant_store_status');

                $table->foreign(['tenant_id', 'store_id'], 'fk_pc_store')
                    ->references(['tenant_id', 'id'])->on('stores')->onDelete('restrict');
                $table->foreign(['tenant_id', 'store_id', 'device_id'], 'fk_pc_device')
                    ->references(['tenant_id', 'store_id', 'id'])->on('devices')->onDelete('restrict');
                $table->foreign(['tenant_id', 'store_id', 'shift_id'], 'fk_pc_shift')
                    ->references(['tenant_id', 'store_id', 'id'])->on('shifts')->onDelete('restrict');
                $table->foreign(['tenant_id', 'parked_by_user_id'], 'fk_pc_user')
                    ->references(['tenant_id', 'id'])->on('users')->onDelete('restrict');
                $table->foreign('customer_id', 'fk_pc_customer')
                    ->references('id')->on('customers')->nullOnDelete();
                $table->foreign('table_id', 'fk_pc_table')
                    ->references('id')->on('tables')->nullOnDelete();
                $table->foreign('discount_id', 'fk_pc_discount')
                    ->references('id')->on('discounts')->nullOnDelete();
                $table->foreign('resumed_by_user_id', 'fk_pc_resumed_user')
                    ->references('id')->on('users')->nullOnDelete();
                $table->foreign('converted_order_id', 'fk_pc_converted_order')
                    ->references('id')->on('orders')->nullOnDelete();
            });

            DB::statement("ALTER TABLE parked_carts ADD CONSTRAINT chk_pc_status_enum CHECK (status IN ('parked', 'resumed', 'converted', 'expired', 'discarded'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parked_carts');
        Schema::dropIfExists('discount_redemptions');

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (Schema::hasColumn('order_items', 'item_discount_id')) {
                    $table->dropForeign(['item_discount_id']);
                    $table->dropColumn(['item_discount_id', 'item_discount_amount', 'allocated_order_discount_amount', 'net_line_total']);
                }
            });
        }

        if (Schema::hasTable('discounts')) {
            Schema::table('discounts', function (Blueprint $table) {
                if (Schema::hasColumn('discounts', 'usage_limit')) {
                    $table->dropColumn(['usage_limit', 'is_stackable', 'scope']);
                }
            });
        }
    }
};
