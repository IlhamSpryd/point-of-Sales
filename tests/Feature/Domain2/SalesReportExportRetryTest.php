<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\ExportTaskStatus;
use App\Jobs\ProcessSalesReportExportJob;
use App\Models\ExportTask;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SalesReportExportRetryTest extends TestCase
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
            'name' => 'Report Tenant',
            'readiness_status' => 'active',
        ]);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Report Store',
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);
    }

    public function test_sales_report_export_job_executes_when_task_status_is_processing(): void
    {
        Storage::fake('local');

        // Simulate that attempt 1 already transitioned status to Processing
        $task = ExportTask::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'requested_by' => $this->user->id,
            'type' => 'sales_report',
            'parameters' => [
                'start' => now()->startOfMonth()->toDateString(),
                'end' => now()->toDateString(),
            ],
            'status' => ExportTaskStatus::Processing,
        ]);

        $job = new ProcessSalesReportExportJob($task, $this->tenant->id, $this->store->id);
        $job->handle();

        $task->refresh();
        $this->assertEquals(ExportTaskStatus::Completed, $task->status, 'Export task must complete even if starting in Processing state on retry');
        $this->assertNotNull($task->file_path);
        $this->assertNull($task->error_message);
    }
}
