<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Core\Context\TenantContext;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductImportService;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Subscription\Models\Plan;
use App\Modules\Subscription\Models\PlanEntitlement;
use App\Modules\Subscription\Models\TenantSubscription;
use App\Modules\Subscription\Services\EntitlementService;
use App\Modules\Subscription\Services\SubscriptionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Wave6SaasEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected SubscriptionLifecycleService $lifecycleService;

    protected EntitlementService $entitlementService;

    protected ProductImportService $importService;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->lifecycleService = app(SubscriptionLifecycleService::class);
        $this->entitlementService = app(EntitlementService::class);
        $this->importService = app(ProductImportService::class);
    }

    /**
     * SAAS-01: startTrial() mengisi status 'trialing', mengisi starts_at, ends_at, trial_ends_at,
     * dan memastikan isTrialing() serta hasFeature() bernilai true.
     */
    public function test_start_trial_sets_trialing_status_dates_and_enables_features(): void
    {
        $plan = Plan::create([
            'name' => 'Enterprise Trial Plan',
            'code' => 'enterprise_trial_'.uniqid(),
            'price' => 500000,
            'is_active' => true,
        ]);

        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'feature_code' => EntitlementService::FEATURE_RESTOCK_FORECAST,
            'value' => 'true',
        ]);

        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'feature_code' => EntitlementService::FEATURE_OMNICHANNEL,
            'value' => 'true',
        ]);

        $sub = $this->lifecycleService->startTrial($this->tenant, $plan, 14);

        // Verifikasi model TenantSubscription
        $this->assertNotNull($sub);
        $this->assertEquals(TenantSubscription::STATUS_TRIALING, $sub->status);
        $this->assertNotNull($sub->starts_at);
        $this->assertNotNull($sub->ends_at);
        $this->assertNotNull($sub->trial_ends_at);
        $this->assertTrue($sub->isTrialing());
        $this->assertTrue($sub->isActive());

        // Verifikasi pembaruan pada Tenant
        $this->tenant->refresh();
        $this->assertEquals($plan->code, $this->tenant->subscription_plan);
        $this->assertEquals(Tenant::ACCESS_ACTIVE, $this->tenant->access_status);

        // Verifikasi EntitlementService
        $this->assertTrue($this->entitlementService->hasFeature($this->tenant->id, EntitlementService::FEATURE_RESTOCK_FORECAST));
        $this->assertTrue($this->entitlementService->hasFeature($this->tenant->id, EntitlementService::FEATURE_OMNICHANNEL));
    }

    /**
     * SIMULASI D (SAAS-02):
     * Tenant paket Starter dengan kuota 100 produk, sudah memiliki 50 produk.
     * Mengimpor file 60 produk dalam strictMode -> gagal total (ValidationException),
     * jumlah produk tetap 50 (atomic rollback).
     */
    public function test_simulation_d_quota_exceeded_on_product_import_fails_completely_in_strict_mode(): void
    {
        $plan = Plan::create([
            'name' => 'Starter Plan',
            'code' => 'starter_'.uniqid(),
            'price' => 99000,
            'is_active' => true,
        ]);

        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'feature_code' => EntitlementService::QUOTA_MAX_PRODUCTS,
            'value' => '100',
        ]);

        $this->lifecycleService->startTrial($this->tenant, $plan, 14);

        // Buat kategori default
        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'General',
            'is_active' => true,
        ]);

        // 1. Buat 50 produk awal
        for ($i = 1; $i <= 50; $i++) {
            Product::create([
                'tenant_id' => $this->tenant->id,
                'category_id' => $category->id,
                'product_name' => "Existing Product {$i}",
                'product_code' => "SKU-EXIST-{$i}",
                'product_price' => 10000,
                'is_active' => true,
                'status' => 'active',
            ]);
        }

        $this->assertEquals(50, Product::where('tenant_id', $this->tenant->id)->count());

        // 2. Siapkan 60 produk impor (50 + 60 = 110 > kuota 100)
        $importRows = [];
        for ($j = 1; $j <= 60; $j++) {
            $importRows[] = [
                'product_name' => "Import Product {$j}",
                'sku' => "SKU-IMP-{$j}",
                'price' => 25000,
            ];
        }

        // 3. Impor dalam strictMode wajib melempar ValidationException
        $exceptionThrown = false;
        try {
            $this->importService->importRows($importRows, $this->tenant->id, strictMode: true);
        } catch (ValidationException $e) {
            $exceptionThrown = true;
            $this->assertArrayHasKey('quota', $e->errors());
        }

        $this->assertTrue($exceptionThrown, 'Seharusnya melempar ValidationException karena kuota 100 terlampaui');

        // 4. Pastikan proteksi atomik: jumlah produk di database tetap 50
        $this->assertEquals(50, Product::where('tenant_id', $this->tenant->id)->count());
    }

    /**
     * SAAS-03: Dalam mode non-strict (best-effort), impor produk memotong baris berlebih
     * sesuai sisa kuota tanpa merusak baris yang diizinkan.
     */
    public function test_best_effort_import_truncates_at_remaining_quota_when_not_in_strict_mode(): void
    {
        $plan = Plan::create([
            'name' => 'Growth Plan',
            'code' => 'growth_'.uniqid(),
            'price' => 199000,
            'is_active' => true,
        ]);

        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'feature_code' => EntitlementService::QUOTA_MAX_PRODUCTS,
            'value' => '20',
        ]);

        $this->lifecycleService->startTrial($this->tenant, $plan, 14);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'General',
            'is_active' => true,
        ]);

        // Buat 15 produk awal (sisa kuota: 5)
        for ($i = 1; $i <= 15; $i++) {
            Product::create([
                'tenant_id' => $this->tenant->id,
                'category_id' => $category->id,
                'product_name' => "Growth Product {$i}",
                'product_code' => "SKU-GR-{$i}",
                'product_price' => 15000,
                'is_active' => true,
                'status' => 'active',
            ]);
        }

        $this->assertEquals(15, Product::where('tenant_id', $this->tenant->id)->count());

        // Siapkan 10 produk impor (15 + 10 = 25 > 20)
        $importRows = [];
        for ($j = 1; $j <= 10; $j++) {
            $importRows[] = [
                'product_name' => "Truncate Product {$j}",
                'sku' => "SKU-TRUNC-{$j}",
                'price' => 30000,
            ];
        }

        // Jalankan import dalam mode non-strict
        $result = $this->importService->importRows($importRows, $this->tenant->id, strictMode: false);

        // Hanya 5 produk yang diimpor, 5 sisanya tercatat gagal/dilewati
        $this->assertEquals(5, $result['imported_count']);
        $this->assertEquals(5, $result['failed_count']);
        $this->assertEquals(20, Product::where('tenant_id', $this->tenant->id)->count());
    }
}
