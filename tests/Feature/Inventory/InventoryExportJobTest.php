<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\ExportTaskStatus;
use App\Enums\IngredientStockMovementTypeEnum;
use App\Enums\StockMovementType;
use App\Exports\InventoryLedgerExport;
use App\Jobs\ProcessInventoryLedgerExportJob;
use App\Models\ExportTask;
use App\Models\Ingredient;
use App\Modules\Inventory\Services\InventoryLedgerService;
use App\Modules\Reports\Services\ReportService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class InventoryExportJobTest extends TestCase
{
    use RefreshDatabase;
    use TwoTenantFixture;

    protected InventoryLedgerService $ledgerService;

    protected Ingredient $ingredientA;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->setupTwoTenants();

            $this->ingredientA = Ingredient::create([
                'tenant_id' => $this->tenantA->id,
                'name' => 'Kopi Robusta Lampung',
                'ingredient_code' => 'ING-LMP-01',
                'unit' => 'kg',
                'cost_per_unit' => '45000.0000',
            ]);
        });

        $this->ledgerService = app(InventoryLedgerService::class);
    }

    public function test_job_processes_inventory_ledger_export_successfully(): void
    {
        Storage::fake('local');

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        // Record a product movement
        $this->ledgerService->recordMovement(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $this->productA->id,
            quantityDelta: '25.0000',
            type: StockMovementType::GoodsReceipt,
            unitCost: '12000.0000',
            reason: 'Export test goods receipt'
        );

        // Record an ingredient movement
        $this->ledgerService->recordMovement(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            itemType: 'ingredient',
            itemId: $this->ingredientA->id,
            quantityDelta: '10.0000',
            type: IngredientStockMovementTypeEnum::GoodsReceipt,
            unitCost: '45000.0000',
            reason: 'Export test ingredient receipt'
        );

        $task = ExportTask::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'requested_by' => $this->userAOwner->id,
            'type' => 'inventory_ledger',
            'parameters' => [
                'start' => now()->subDay()->toDateString(),
                'end' => now()->addDay()->toDateString(),
                'store_id' => $this->storeA->id,
                'item_type' => 'all',
            ],
            'status' => ExportTaskStatus::Pending,
        ]);

        $job = new ProcessInventoryLedgerExportJob($task, $this->tenantA->id, $this->storeA->id);
        $job->handle();

        $task->refresh();
        $this->assertSame(ExportTaskStatus::Completed, $task->status);
        $this->assertNotNull($task->file_path);
        $this->assertStringEndsWith('.xlsx', $task->file_path);
        $this->assertGreaterThan(0, $task->file_size_bytes);
        Storage::disk('local')->assertExists($task->file_path);
    }

    public function test_inventory_ledger_export_through_report_service_queue(): void
    {
        Storage::fake('local');

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $reportService = app(ReportService::class);
        $task = $reportService->queueInventoryLedgerExport(
            start: Carbon::now()->subDays(7),
            end: Carbon::now(),
            requestedBy: $this->userAOwner->id,
            storeId: $this->storeA->id,
            itemType: 'all'
        );

        $this->assertSame(ExportTaskStatus::Pending, $task->status);
        $this->assertSame('inventory_ledger', $task->type);

        $job = new ProcessInventoryLedgerExportJob($task, $this->tenantA->id, $this->storeA->id);
        $job->handle();

        $task->refresh();
        $this->assertSame(ExportTaskStatus::Completed, $task->status);
        $this->assertNotNull($task->file_path);
    }

    public function test_tenant_isolation_fails_if_tenant_id_mismatches_task(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $task = ExportTask::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'requested_by' => $this->userAOwner->id,
            'type' => 'inventory_ledger',
            'parameters' => [
                'start' => '2026-01-01',
                'end' => '2026-01-02',
            ],
            'status' => ExportTaskStatus::Pending,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant ID mismatch between job and task.');

        $job = new ProcessInventoryLedgerExportJob($task, $this->tenantB->id, $this->storeB->id);
        $job->handle();
    }

    public function test_tenant_isolation_fails_if_tenant_id_is_missing(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $task = ExportTask::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'requested_by' => $this->userAOwner->id,
            'type' => 'inventory_ledger',
            'parameters' => [
                'start' => '2026-01-01',
                'end' => '2026-01-02',
            ],
            'status' => ExportTaskStatus::Pending,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant ID is required to process inventory export job.');

        $job = new ProcessInventoryLedgerExportJob($task, 0, $this->storeA->id);
        $job->handle();
    }

    public function test_export_filters_by_item_type(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->ledgerService->recordMovement(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            itemType: 'product',
            itemId: $this->productA->id,
            quantityDelta: '5.0000',
            type: StockMovementType::GoodsReceipt,
            unitCost: '10000.0000',
            reason: 'Filter test product'
        );

        $this->ledgerService->recordMovement(
            tenantId: $this->tenantA->id,
            storeId: $this->storeA->id,
            itemType: 'ingredient',
            itemId: $this->ingredientA->id,
            quantityDelta: '8.0000',
            type: IngredientStockMovementTypeEnum::GoodsReceipt,
            unitCost: '20000.0000',
            reason: 'Filter test ingredient'
        );

        $start = Carbon::now()->subHour();
        $end = Carbon::now()->addHour();

        // 1. Only products
        $exportProd = new InventoryLedgerExport($start, $end, $this->storeA->id, 'product');
        $rowsProd = $exportProd->collection();
        $this->assertNotEmpty($rowsProd);
        foreach ($rowsProd as $r) {
            $this->assertSame('Produk', $r['item_type']);
        }

        // 2. Only ingredients
        $exportIng = new InventoryLedgerExport($start, $end, $this->storeA->id, 'ingredient');
        $rowsIng = $exportIng->collection();
        $this->assertNotEmpty($rowsIng);
        foreach ($rowsIng as $r) {
            $this->assertSame('Bahan Baku', $r['item_type']);
        }

        // 3. All items
        $exportAll = new InventoryLedgerExport($start, $end, $this->storeA->id, 'all');
        $rowsAll = $exportAll->collection();
        $types = $rowsAll->pluck('item_type')->unique()->toArray();
        $this->assertContains('Produk', $types);
        $this->assertContains('Bahan Baku', $types);
    }
}
