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
     */
    public function up(): void
    {
        // 1. Tenants table enhancements: config_version & primary_industry
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'config_version')) {
                $table->unsignedInteger('config_version')->default(1)->after('readiness_checked_at');
            }
            if (! Schema::hasColumn('tenants', 'primary_industry')) {
                $table->string('primary_industry', 32)->default('fnb')->after('country_code');
            }
        });

        // 2. Operational Units table (Store operational subdivisions)
        if (! Schema::hasTable('operational_units')) {
            Schema::create('operational_units', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->string('name', 100);
                $table->string('code', 50);
                $table->string('unit_type', 50)->default('checkout_retail'); // checkout_retail, fnb_dining, fnb_bar, fnb_kitchen, takeaway, service_desk
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'id'], 'operational_units_tenant_id_id_unique');
                $table->unique(['tenant_id', 'store_id', 'code'], 'operational_units_tenant_store_code_unique');

                $table->foreign(['tenant_id', 'store_id'], 'fk_op_units_store_composite')
                    ->references(['tenant_id', 'id'])
                    ->on('stores')
                    ->onDelete('restrict')
                    ->onUpdate('cascade');
            });
        }

        // 3. User Store Roles table (Normalized Multi-Role and Scoped IAM)
        if (! Schema::hasTable('user_store_roles')) {
            Schema::create('user_store_roles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('role_id');
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'user_id', 'store_id', 'role_id'], 'unique_user_store_role');

                $table->foreign(['tenant_id', 'store_id'], 'fk_usr_store_composite')
                    ->references(['tenant_id', 'id'])
                    ->on('stores')
                    ->onDelete('restrict')
                    ->onUpdate('cascade');

                $table->foreign(['tenant_id', 'role_id'], 'fk_usr_role_composite')
                    ->references(['tenant_id', 'id'])
                    ->on('roles')
                    ->onDelete('restrict')
                    ->onUpdate('cascade');

                $table->foreign(['tenant_id', 'user_id'], 'fk_usr_user_composite')
                    ->references(['tenant_id', 'id'])
                    ->on('users')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });
        }

        // 4. Capability Registry: Definitions
        if (! Schema::hasTable('capability_definitions')) {
            Schema::create('capability_definitions', function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->unique();
                $table->string('name', 100);
                $table->string('industry', 32)->default('core'); // core, fnb, retail, hybrid
                $table->text('description')->nullable();
                $table->json('dependencies')->nullable(); // array of required capability codes
                $table->json('incompatible_with')->nullable(); // array of mutually exclusive codes
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 5. Tenant Capabilities (Tenant-level activation and entitlement)
        if (! Schema::hasTable('tenant_capabilities')) {
            Schema::create('tenant_capabilities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('capability_code', 64);
                $table->boolean('is_enabled')->default(true);
                $table->json('configuration')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'capability_code'], 'unique_tenant_capability');

                $table->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('cascade');
            });
        }

        // 6. Store Capabilities (Store-level override)
        if (! Schema::hasTable('store_capabilities')) {
            Schema::create('store_capabilities', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('store_id');
                $table->string('capability_code', 64);
                $table->boolean('is_enabled');
                $table->json('configuration')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'store_id', 'capability_code'], 'unique_store_capability');

                $table->foreign(['tenant_id', 'store_id'], 'fk_store_cap_composite')
                    ->references(['tenant_id', 'id'])
                    ->on('stores')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });
        }

        // 7. Payment Inbox (Absolute deduplication & async inbox pattern)
        if (! Schema::hasTable('payment_inbox')) {
            Schema::create('payment_inbox', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('provider', 32);
                $table->string('idempotency_key', 191);
                $table->string('event_type', 64);
                $table->longText('payload');
                $table->string('status', 32)->default('pending'); // pending, processed, failed
                $table->text('error_message')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'idempotency_key'], 'unique_tenant_payment_inbox');

                $table->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('cascade');
            });
        }

        // 8. Devices: Add operational_unit_id
        Schema::table('devices', function (Blueprint $table) {
            if (! Schema::hasColumn('devices', 'operational_unit_id')) {
                $table->unsignedBigInteger('operational_unit_id')->nullable()->after('store_id');
                $table->index(['tenant_id', 'operational_unit_id'], 'idx_devices_tenant_operational_unit');

                $table->foreign('operational_unit_id', 'fk_devices_operational_unit')
                    ->references('id')
                    ->on('operational_units')
                    ->onDelete('set null');
            }
        });

        // 9. Orders: Add operational_unit_id and Bounded State Machine columns
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'operational_unit_id')) {
                $table->unsignedBigInteger('operational_unit_id')->nullable()->after('store_id');
            }
            if (! Schema::hasColumn('orders', 'order_lifecycle_status')) {
                $table->string('order_lifecycle_status', 30)->default('submitted')->after('order_status');
            }
            if (! Schema::hasColumn('orders', 'payment_status')) {
                $table->string('payment_status', 30)->default('unpaid')->after('order_lifecycle_status');
            }
            if (! Schema::hasColumn('orders', 'fulfillment_status')) {
                $table->string('fulfillment_status', 30)->default('unfulfilled')->after('payment_status');
            }
        });

        // 10. Order Items: Add fulfillment dimensions
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'fulfillment_channel')) {
                $table->string('fulfillment_channel', 30)->default('on_premise_dine_in')->after('preparation_status');
            }
            if (! Schema::hasColumn('order_items', 'kitchen_prep_status')) {
                $table->string('kitchen_prep_status', 30)->default('queued')->after('fulfillment_channel');
            }
            if (! Schema::hasColumn('order_items', 'shipping_dispatch_status')) {
                $table->string('shipping_dispatch_status', 30)->default('not_applicable')->after('kitchen_prep_status');
            }
        });

        // Backfill data safely
        $this->seedInitialData();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'shipping_dispatch_status')) {
                $table->dropColumn(['shipping_dispatch_status', 'kitchen_prep_status', 'fulfillment_channel']);
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'fulfillment_status')) {
                $table->dropColumn(['fulfillment_status', 'payment_status', 'order_lifecycle_status', 'operational_unit_id']);
            }
        });

        Schema::table('devices', function (Blueprint $table) {
            if (Schema::hasColumn('devices', 'operational_unit_id')) {
                $table->dropForeign('fk_devices_operational_unit');
                $table->dropIndex('idx_devices_tenant_operational_unit');
                $table->dropColumn('operational_unit_id');
            }
        });

        Schema::dropIfExists('payment_inbox');
        Schema::dropIfExists('store_capabilities');
        Schema::dropIfExists('tenant_capabilities');
        Schema::dropIfExists('capability_definitions');
        Schema::dropIfExists('user_store_roles');
        Schema::dropIfExists('operational_units');

        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'primary_industry')) {
                $table->dropColumn('primary_industry');
            }
            if (Schema::hasColumn('tenants', 'config_version')) {
                $table->dropColumn('config_version');
            }
        });
    }

    private function seedInitialData(): void
    {
        // 1. Backfill default operational units for all stores
        $stores = DB::table('stores')->select('id', 'tenant_id', 'name')->get();
        foreach ($stores as $store) {
            $unitExists = DB::table('operational_units')
                ->where('tenant_id', $store->tenant_id)
                ->where('store_id', $store->id)
                ->where('code', 'MAIN')
                ->exists();

            if (! $unitExists) {
                $unitId = DB::table('operational_units')->insertGetId([
                    'tenant_id' => $store->tenant_id,
                    'store_id' => $store->id,
                    'name' => 'Main Operational Unit',
                    'code' => 'MAIN',
                    'unit_type' => 'checkout_retail',
                    'is_default' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Link existing store devices to this default unit
                DB::table('devices')
                    ->where('tenant_id', $store->tenant_id)
                    ->where('store_id', $store->id)
                    ->whereNull('operational_unit_id')
                    ->update(['operational_unit_id' => $unitId]);
            }
        }

        // 2. Seed canonical capability definitions
        $capabilities = [
            [
                'code' => 'core.pos_cashier',
                'name' => 'POS Cashier & Checkout',
                'industry' => 'core',
                'description' => 'Standard frontline checkout, cash drawer, and digital receipt generation.',
                'dependencies' => null,
                'incompatible_with' => null,
            ],
            [
                'code' => 'core.inventory_ledger',
                'name' => 'Inventory Ledger & Movements',
                'industry' => 'core',
                'description' => 'Double-entry stock movement tracking and immutable ledger records.',
                'dependencies' => null,
                'incompatible_with' => null,
            ],
            [
                'code' => 'core.multi_branch',
                'name' => 'Multi-Branch & Store Switching',
                'industry' => 'core',
                'description' => 'Enterprise branch configuration, store-specific inventory, and branch switching.',
                'dependencies' => null,
                'incompatible_with' => null,
            ],
            [
                'code' => 'fnb.table_management',
                'name' => 'Table & Floor Management',
                'industry' => 'fnb',
                'description' => 'Visual table layouts, seat mapping, and table occupancy state machine.',
                'dependencies' => null,
                'incompatible_with' => null,
            ],
            [
                'code' => 'fnb.kitchen_display',
                'name' => 'Kitchen Display System (KDS)',
                'industry' => 'fnb',
                'description' => 'Live station order routing, prep timers, and ticket bump bars.',
                'dependencies' => null,
                'incompatible_with' => null,
            ],
            [
                'code' => 'fnb.recipe_management',
                'name' => 'Recipe BOM & Ingredient Deductions',
                'industry' => 'fnb',
                'description' => 'Bill of Materials recipe mapping and automated raw material deduction.',
                'dependencies' => null,
                'incompatible_with' => null,
            ],
            [
                'code' => 'fnb.waiter_ordering',
                'name' => 'Waiter Mobile Order Entry',
                'industry' => 'fnb',
                'description' => 'Table-side mobile waiter ordering terminal with live kitchen dispatch.',
                'dependencies' => json_encode(['fnb.table_management']),
                'incompatible_with' => null,
            ],
            [
                'code' => 'retail.barcode_scanning',
                'name' => 'Retail Barcode & Fast Scanning',
                'industry' => 'retail',
                'description' => 'High-velocity retail barcode reader and quick item entry.',
                'dependencies' => null,
                'incompatible_with' => null,
            ],
            [
                'code' => 'retail.stock_transfer',
                'name' => 'Inter-Branch Stock Transfers',
                'industry' => 'retail',
                'description' => 'In-transit stock tracking and inter-warehouse goods transfer workflow.',
                'dependencies' => json_encode(['core.inventory_ledger']),
                'incompatible_with' => null,
            ],
            [
                'code' => 'retail.product_variants',
                'name' => 'Matrix Product Variants & SKUs',
                'industry' => 'retail',
                'description' => 'Multi-dimensional product matrix (Size, Color, Material) with independent SKUs.',
                'dependencies' => null,
                'incompatible_with' => null,
            ],
            [
                'code' => 'retail.shipping_fulfillment',
                'name' => 'Omnichannel Shipping & Fulfillment',
                'industry' => 'retail',
                'description' => 'Pick, pack, courier dispatch, and shipping tracking integration.',
                'dependencies' => null,
                'incompatible_with' => null,
            ],
        ];

        foreach ($capabilities as $cap) {
            DB::table('capability_definitions')->updateOrInsert(
                ['code' => $cap['code']],
                array_merge($cap, [
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        // 3. Backfill tenant capabilities for all existing tenants
        $tenants = DB::table('tenants')->select('id', 'primary_industry')->get();
        $allCapCodes = collect($capabilities)->pluck('code')->all();

        foreach ($tenants as $tenant) {
            foreach ($allCapCodes as $capCode) {
                DB::table('tenant_capabilities')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'capability_code' => $capCode],
                    [
                        'is_enabled' => true,
                        'configuration' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        // 4. Backfill user_store_roles from users.role_id and user_stores
        $users = DB::table('users')
            ->whereNotNull('role_id')
            ->whereNotNull('tenant_id')
            ->select('id', 'tenant_id', 'role_id')
            ->get();

        foreach ($users as $user) {
            $userStores = DB::table('user_stores')->where('user_id', $user->id)->pluck('store_id');

            if ($userStores->isEmpty()) {
                // If user has no explicit store pivot (e.g. Owner or Tenant Admin), assign to all tenant stores
                $userStores = DB::table('stores')->where('tenant_id', $user->tenant_id)->pluck('id');
            }

            foreach ($userStores as $storeId) {
                DB::table('user_store_roles')->updateOrInsert(
                    [
                        'tenant_id' => $user->tenant_id,
                        'user_id' => $user->id,
                        'store_id' => $storeId,
                        'role_id' => $user->role_id,
                    ],
                    [
                        'is_active' => true,
                        'valid_from' => null,
                        'valid_to' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        // 5. Backfill bounded state machines on existing orders
        DB::table('orders')->where('order_status', 'paid')->update([
            'order_lifecycle_status' => 'confirmed',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
        ]);

        DB::table('orders')->where('order_status', 'completed')->update([
            'order_lifecycle_status' => 'completed',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
        ]);

        DB::table('orders')->where('order_status', 'pending')->update([
            'order_lifecycle_status' => 'submitted',
            'payment_status' => 'awaiting_payment',
            'fulfillment_status' => 'unfulfilled',
        ]);

        DB::table('orders')->whereIn('order_status', ['cancelled', 'expired', 'failed'])->update([
            'order_lifecycle_status' => 'cancelled',
            'payment_status' => 'unpaid',
            'fulfillment_status' => 'unfulfilled',
        ]);
    }
};
