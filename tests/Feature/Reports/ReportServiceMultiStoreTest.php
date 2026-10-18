<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exports\SalesExport;
use App\Models\Order;
use App\Services\Context\TenantContext;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class ReportServiceMultiStoreTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();
        });
    }

    public function test_get_sales_summary_supports_all_stores_and_single_store(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $today = Carbon::today();

        // Create paid order in Store A
        Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-A1',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 50000,
            'order_date' => $today,
            'payment_method' => PaymentMethod::Cash->value,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        // Create paid order in Store A2 (same tenant, different store)
        $context->runAs($this->tenantA->id, $this->storeA2->id, function () use ($today) {
            Order::forceCreate([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $this->storeA2->id,
                'user_id' => $this->userAOwner->id,
                'order_code' => 'ORD-A2',
                'order_status' => OrderStatus::Paid->value,
                'order_amount' => 75000,
                'order_date' => $today,
                'payment_method' => PaymentMethod::Cash->value,
                'idempotency_key' => Str::uuid()->toString(),
            ]);
        });

        // Create paid order in Tenant B (should NEVER be included)
        $context->runAs($this->tenantB->id, $this->storeB->id, function () use ($today) {
            Order::forceCreate([
                'tenant_id' => $this->tenantB->id,
                'store_id' => $this->storeB->id,
                'user_id' => $this->userBBarista->id,
                'order_code' => 'ORD-B1',
                'order_status' => OrderStatus::Paid->value,
                'order_amount' => 999000,
                'order_date' => $today,
                'payment_method' => PaymentMethod::Cash->value,
                'idempotency_key' => Str::uuid()->toString(),
            ]);
        });

        $reportService = app(ReportService::class);

        // When allStores = false: only Store A orders
        $singleStoreSummary = $reportService->getSalesSummary($today->copy()->startOfDay(), $today->copy()->endOfDay(), allStores: false);
        $this->assertEquals(1, $singleStoreSummary->count);
        $this->assertEquals(50000, $singleStoreSummary->total);

        // When allStores = true: both Store A and Store A2 orders, but NOT Tenant B
        $allStoresSummary = $reportService->getSalesSummary($today->copy()->startOfDay(), $today->copy()->endOfDay(), allStores: true);
        $this->assertEquals(2, $allStoresSummary->count);
        $this->assertEquals(125000, $allStoresSummary->total);

        // Verify SalesExport query
        $exportSingle = new SalesExport($today->copy()->startOfDay(), $today->copy()->endOfDay(), allStores: false);
        $this->assertEquals(1, $exportSingle->query()->count());

        $exportAll = new SalesExport($today->copy()->startOfDay(), $today->copy()->endOfDay(), allStores: true);
        $this->assertEquals(2, $exportAll->query()->count());
    }
}
