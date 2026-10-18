<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabel Katalog Paket (plans)
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('billing_cycle', 20)->default('monthly'); // monthly, yearly
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Tabel Fitur & Limit Paket (plan_entitlements)
        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id');
            $table->string('feature_code', 50);
            $table->string('feature_type', 20)->default('boolean'); // boolean, quota
            $table->string('value', 100); // true, false, 1, 5, unlimited, -1
            $table->timestamps();

            $table->foreign('plan_id', 'plan_entitlements_plan_id_foreign')
                ->references('id')->on('plans')
                ->onDelete('CASCADE')->onUpdate('CASCADE');

            $table->unique(['plan_id', 'feature_code'], 'plan_entitlements_unique');
        });

        // 3. Tabel Langganan Tenant (tenant_subscriptions)
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('plan_id');
            $table->string('status', 30)->default('trialing'); // trialing, active, past_due, canceled, expired
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id', 'tenant_subscriptions_tenant_id_foreign')
                ->references('id')->on('tenants')
                ->onDelete('CASCADE')->onUpdate('CASCADE');

            $table->foreign('plan_id', 'tenant_subscriptions_plan_id_foreign')
                ->references('id')->on('plans')
                ->onDelete('RESTRICT')->onUpdate('CASCADE');

            $table->index(['tenant_id', 'status'], 'tenant_subscriptions_status_index');
        });

        // 4. Tabel Dispensasi / Override Entitlement Per Tenant (tenant_entitlement_overrides)
        Schema::create('tenant_entitlement_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('feature_code', 50);
            $table->string('feature_type', 20)->default('boolean'); // boolean, quota
            $table->string('value', 100);
            $table->timestamp('expires_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id', 'tenant_overrides_tenant_id_foreign')
                ->references('id')->on('tenants')
                ->onDelete('CASCADE')->onUpdate('CASCADE');

            $table->unique(['tenant_id', 'feature_code'], 'tenant_overrides_unique');
        });

        // Inisialisasi Baseline Katalog Paket & Fitur
        $now = now();
        $plans = [
            [
                'code' => 'standard_trial',
                'name' => 'Standard Trial',
                'description' => 'Paket uji coba operasional lengkap 14 hari',
                'price' => 0,
                'entitlements' => [
                    'restock_forecast' => ['boolean', 'true'],
                    'omnichannel' => ['boolean', 'true'],
                    'async_export' => ['boolean', 'true'],
                    'max_stores' => ['quota', '1'],
                    'max_devices_per_store' => ['quota', '2'],
                    'max_employees' => ['quota', '5'],
                ],
            ],
            [
                'code' => 'starter',
                'name' => 'Starter F&B',
                'description' => 'Paket dasar outlet tunggal untuk UMKM',
                'price' => 149000,
                'entitlements' => [
                    'restock_forecast' => ['boolean', 'false'],
                    'omnichannel' => ['boolean', 'false'],
                    'async_export' => ['boolean', 'false'],
                    'max_stores' => ['quota', '1'],
                    'max_devices_per_store' => ['quota', '2'],
                    'max_employees' => ['quota', '5'],
                ],
            ],
            [
                'code' => 'pro',
                'name' => 'Professional Growth',
                'description' => 'Paket multi-cabang dengan analitik restok dan ekspor asinkron',
                'price' => 399000,
                'entitlements' => [
                    'restock_forecast' => ['boolean', 'true'],
                    'omnichannel' => ['boolean', 'true'],
                    'async_export' => ['boolean', 'true'],
                    'max_stores' => ['quota', '5'],
                    'max_devices_per_store' => ['quota', '5'],
                    'max_employees' => ['quota', '25'],
                ],
            ],
            [
                'code' => 'enterprise',
                'name' => 'Enterprise Scale',
                'description' => 'Paket tanpa batas untuk jaringan resto dan franchise besar',
                'price' => 999000,
                'entitlements' => [
                    'restock_forecast' => ['boolean', 'true'],
                    'omnichannel' => ['boolean', 'true'],
                    'async_export' => ['boolean', 'true'],
                    'max_stores' => ['quota', 'unlimited'],
                    'max_devices_per_store' => ['quota', 'unlimited'],
                    'max_employees' => ['quota', 'unlimited'],
                ],
            ],
        ];

        foreach ($plans as $p) {
            $entitlements = $p['entitlements'];
            unset($p['entitlements']);
            $p['created_at'] = $now;
            $p['updated_at'] = $now;

            $planId = DB::table('plans')->insertGetId($p);

            foreach ($entitlements as $featureCode => [$type, $val]) {
                DB::table('plan_entitlements')->insert([
                    'plan_id' => $planId,
                    'feature_code' => $featureCode,
                    'feature_type' => $type,
                    'value' => $val,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_entitlement_overrides');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('plans');
    }
};
