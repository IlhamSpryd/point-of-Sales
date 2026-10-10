<?php

namespace Tests\Feature\Tenancy;

use App\Enums\ExportTaskStatus;
use App\Jobs\ProcessSalesReportExportJob;
use App\Models\ExportTask;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessSalesReportExportJobTest extends TestCase
{
    use RefreshDatabase;

    private $tenantA;

    private $tenantB;

    private $userA;

    private $taskA;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->runWithoutTenant(function () {
            $this->tenantA = Tenant::forceCreate(['name' => 'Tenant A']);
            $this->tenantB = Tenant::forceCreate(['name' => 'Tenant B']);
            $storeA = Store::forceCreate(['tenant_id' => $this->tenantA->id, 'name' => 'Store A']);

            $this->userA = User::forceCreate([
                'tenant_id' => $this->tenantA->id,
                'name' => 'User A',
                'email' => 'a@test.com',
                'password' => bcrypt('password'),
                'is_active' => true,
            ]);

            $this->taskA = ExportTask::forceCreate([
                'tenant_id' => $this->tenantA->id,
                'store_id' => $storeA->id,
                'requested_by' => $this->userA->id,
                'type' => 'sales_report',
                'parameters' => ['start' => '2023-01-01', 'end' => '2023-01-31'],
                'status' => ExportTaskStatus::Pending,
            ]);
        });
    }

    public function test_job_processes_successfully_with_valid_tenant_id()
    {
        // Execute job with Tenant A's context
        $job = new ProcessSalesReportExportJob($this->taskA, $this->tenantA->id);
        $job->handle();

        // If it reaches here without exception, success.
        $this->taskA->refresh();
        $this->assertEquals(ExportTaskStatus::Completed, $this->taskA->status);
    }

    public function test_job_fails_if_tenant_id_is_missing()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant ID is required to process export job.');

        // Pass 0 as tenantId to test the empty check in handle()
        $job = new ProcessSalesReportExportJob($this->taskA, 0);
        $job->handle();
    }

    public function test_job_fails_if_tenant_id_mismatches_task_tenant_id()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant ID mismatch between job and task.');

        // Pass Tenant B's ID to process Tenant A's task
        $job = new ProcessSalesReportExportJob($this->taskA, $this->tenantB->id);
        $job->handle();
    }
}
