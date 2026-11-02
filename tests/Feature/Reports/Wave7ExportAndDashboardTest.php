<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ExportTaskStatus;
use App\Enums\OrderStatus;
use App\Jobs\ProcessAnalyticsExportJob;
use App\Livewire\Reports\AdvancedAnalyticsDashboard;
use App\Models\Category;
use App\Models\ExportTask;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TwoTenantFixture;
use Tests\TestCase;

class Wave7ExportAndDashboardTest extends TestCase
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

    /**
     * T-RBI-09: ProcessAnalyticsExportJob executes on queue 'exports', writes CSV, and completes ExportTask.
     */
    public function test_t_rbi_09_process_analytics_export_job_generates_csv_and_completes_task(): void
    {
        Storage::fake('local');

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $cat = Category::create([
            'tenant_id' => $this->tenantA->id,
            'category_name' => 'Export Category',
            'category_code' => 'EXP-CAT',
        ]);

        $prod = Product::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'category_id' => $cat->id,
            'product_name' => 'Export Item',
            'product_code' => 'EXP-ITEM',
            'product_price' => 50000.00,
            'standard_cost' => 25000.00,
            'stock' => 50,
            'status' => 'active',
            'is_active' => true,
        ]);

        $order = Order::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'user_id' => $this->userAOwner->id,
            'order_code' => 'ORD-EXP-1',
            'order_status' => OrderStatus::Paid->value,
            'order_amount' => 100000.00,
            'subtotal_amount' => 100000.00,
            'order_date' => Carbon::now()->toDateString(),
            'created_at' => Carbon::now()->toDateTimeString(),
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        OrderItem::forceCreate([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'order_id' => $order->id,
            'product_id' => $prod->id,
            'qty' => 2,
            'order_price' => 50000.00,
            'order_subtotal' => 100000.00,
        ]);

        // 1. Uji ekspor sales_analytics
        $exportTask = ExportTask::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'requested_by' => $this->userAOwner->id,
            'type' => 'sales_analytics',
            'parameters' => [
                'start_date' => Carbon::now()->subDays(7)->toDateString(),
                'end_date' => Carbon::now()->toDateString(),
                'store_id' => $this->storeA->id,
            ],
            'status' => ExportTaskStatus::Pending,
        ]);

        $job = new ProcessAnalyticsExportJob($exportTask, $this->tenantA->id, $this->storeA->id);
        $this->assertEquals('exports', $job->queue);

        app()->call([$job, 'handle']);

        $exportTask->refresh();
        $this->assertEquals(ExportTaskStatus::Completed, $exportTask->status);

        $filePath = $exportTask->file_path;
        $this->assertNotNull($filePath);
        $this->assertGreaterThan(0, $exportTask->file_size_bytes);
        $this->assertNotNull($exportTask->completed_at);
        Storage::disk('local')->assertExists($filePath);

        $csvContent = (string) Storage::disk('local')->get($filePath);
        $this->assertStringContainsString('METRIK PENJUALAN', $csvContent);
        $this->assertStringContainsString('Export Item', $csvContent);

        // 2. Uji ekspor financial_bi
        $finTask = ExportTask::create([
            'tenant_id' => $this->tenantA->id,
            'store_id' => $this->storeA->id,
            'requested_by' => $this->userAOwner->id,
            'type' => 'financial_bi',
            'parameters' => [
                'start_date' => Carbon::now()->subDays(30)->toDateString(),
                'end_date' => Carbon::now()->toDateString(),
                'store_id' => $this->storeA->id,
            ],
            'status' => ExportTaskStatus::Pending,
        ]);

        $finJob = new ProcessAnalyticsExportJob($finTask, $this->tenantA->id, $this->storeA->id);
        app()->call([$finJob, 'handle']);

        $finTask->refresh();
        $this->assertEquals(ExportTaskStatus::Completed, $finTask->status);

        $finPath = $finTask->file_path;
        $this->assertNotNull($finPath);
        Storage::disk('local')->assertExists($finPath);
        $finContent = (string) Storage::disk('local')->get($finPath);
        $this->assertStringContainsString('RINGKASAN LABA RUGI BUKU BESAR', $finContent);
    }

    /**
     * T-RBI-10: AdvancedAnalyticsDashboard Livewire UI mounts, checks authorization, tab switching, and dispatches export.
     */
    public function test_t_rbi_10_dashboard_livewire_wiring_and_dispatch(): void
    {
        Queue::fake();

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenantA->id);
        $context->setStoreId($this->storeA->id);

        $this->actingAs($this->userAOwner);

        // 1. Mount & Initial State (tab Penjualan)
        $component = Livewire::test(AdvancedAnalyticsDashboard::class);
        $component->assertSet('activeTab', 'sales')
            ->assertSee('Business Intelligence', false)
            ->assertSee('Advanced Analytics', false)
            ->assertSee('Top 10 Produk Terlaris', false)
            // Switch ke tab Persediaan
            ->call('setTab', 'inventory')
            ->assertSet('activeTab', 'inventory')
            ->assertSee('Distribusi Umur Stok', false)
            // Switch ke tab Keuangan
            ->call('setTab', 'finance')
            ->assertSet('activeTab', 'finance')
            ->assertSee('Rincian Pos Akun Buku Besar', false)
            // Switch ke tab Pelanggan
            ->call('setTab', 'customers')
            ->assertSet('activeTab', 'customers')
            ->assertSee('Segmentasi Pelanggan RFM', false)
            // Dispatch export task
            ->call('dispatchExport', 'sales_analytics')
            ->assertSee('Tugas ekspor', false);

        // Pastikan task tersimpan di DB dan di-dispatch ke queue 'exports'
        $task = ExportTask::where('tenant_id', $this->tenantA->id)->latest('id')->first();
        $this->assertNotNull($task);
        $this->assertEquals('sales_analytics', $task->type);

        Queue::assertPushed(ProcessAnalyticsExportJob::class, function (ProcessAnalyticsExportJob $job) use ($task) {
            return $job->exportTask->id === $task->id && $job->queue === 'exports';
        });

        // 2. Role Guarding: Barista/Kasir harus ditolak (403)
        $this->actingAs($this->userBBarista);
        $context->setTenantId($this->tenantB->id);
        $context->setStoreId($this->storeB->id);

        Livewire::test(AdvancedAnalyticsDashboard::class)
            ->assertStatus(403);
    }
}
