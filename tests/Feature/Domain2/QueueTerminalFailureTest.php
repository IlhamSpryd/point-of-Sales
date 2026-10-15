<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\ExportTaskStatus;
use App\Jobs\ProcessMonthlyExportJob;
use App\Jobs\ProcessSalesReportExportJob;
use App\Models\ExportTask;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QueueTerminalFailureTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected User $user;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Queue Tenant',
            'readiness_status' => 'active',
        ]);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Queue Store',
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);
    }

    public function test_sales_report_export_failed_hook_persists_failure_state(): void
    {
        $task = ExportTask::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'requested_by' => $this->user->id,
            'type' => 'sales_report',
            'parameters' => [
                'start' => '2026-01-01',
                'end' => '2026-01-31',
            ],
            'status' => ExportTaskStatus::Processing,
        ]);

        $job = new ProcessSalesReportExportJob($task, $this->tenant->id, $this->store->id);
        $exception = new \RuntimeException('Disk space exhausted during spreadsheet generation');

        $job->failed($exception);

        $task->refresh();
        $this->assertEquals(ExportTaskStatus::Failed, $task->status);
        $this->assertStringContainsString('Disk space exhausted', (string) $task->error_message);
    }

    public function test_monthly_export_cleans_up_partial_file_on_exception(): void
    {
        Storage::fake('local');

        // Pre-create a prior completed export to verify it is NOT deleted during cleanup
        Storage::disk('local')->put('exports/prior_valid_report.csv', 'header,data');

        // Pre-create an order so chunkById executes its closure
        Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'order_code' => 'POS-EXP-ERR-1',
            'order_date' => '2026-01-15',
            'order_status' => \App\Enums\OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 10000,
            'tax_amount' => 1100,
            'order_amount' => 11100,
            'idempotency_key' => 'exp-err-uuid-1',
            'created_at' => '2026-01-15 10:00:00',
        ]);

        // Simulate a runtime failure inside chunkById during hydration
        Order::retrieved(function () {
            throw new \RuntimeException('Simulated worker crash during CSV streaming');
        });

        $job = new ProcessMonthlyExportJob('01', '2026', $this->tenant->id, $this->store->id);

        try {
            $job->handle();
            $this->fail('Expected RuntimeException was not thrown by job.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Simulated worker crash', $e->getMessage());
        }

        // Verify that the partial file was unlinked and only the prior valid report remains
        $remainingFiles = Storage::disk('local')->allFiles('exports');
        $this->assertEquals(['exports/prior_valid_report.csv'], $remainingFiles, 'Incomplete export file must be deleted upon exception, preserving existing exports');
    }

    public function test_monthly_export_successful_run_creates_csv(): void
    {
        Storage::fake('local');

        // Clear any model listeners
        Order::flushEventListeners();

        Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'order_code' => 'POS-EXP-OK-1',
            'order_date' => '2026-01-15',
            'order_status' => \App\Enums\OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 10000,
            'tax_amount' => 1100,
            'order_amount' => 11100,
            'idempotency_key' => 'exp-ok-uuid-1',
            'created_at' => '2026-01-15 10:00:00',
        ]);

        $job = new ProcessMonthlyExportJob('01', '2026', $this->tenant->id, $this->store->id);
        $job->handle();

        $files = Storage::disk('local')->allFiles('exports');
        $this->assertNotEmpty($files, 'Export file should exist on successful run');
        $content = Storage::disk('local')->get($files[0]);
        $this->assertStringContainsString('POS-EXP-OK-1', $content);
    }
}
