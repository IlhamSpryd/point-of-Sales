<?php

namespace Tests\Feature;

use App\Enums\ExportTaskStatus;
use App\Jobs\ProcessSalesReportExportJob;
use App\Models\ExportTask;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesExportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_implements_should_be_unique()
    {
        $reflection = new \ReflectionClass(ProcessSalesReportExportJob::class);
        $this->assertTrue($reflection->implementsInterface(ShouldBeUnique::class));
    }

    public function test_unique_id_is_based_on_user_and_parameters()
    {
        $user = User::factory()->create();
        $params = ['start' => '2023-01-01', 'end' => '2023-01-31'];
        
        $task = ExportTask::create([
            'requested_by' => $user->id,
            'type' => 'sales_report',
            'parameters' => $params,
            'status' => ExportTaskStatus::Pending->value,
        ]);

        $job = new ProcessSalesReportExportJob($task);
        
        $expectedUniqueId = $user->id . '_' . md5(json_encode($params));
        $this->assertEquals($expectedUniqueId, $job->uniqueId());
    }
}
