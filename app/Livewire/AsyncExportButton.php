<?php

namespace App\Livewire;

use App\Services\ReportService;
use Carbon\Carbon;
use Livewire\Component;

class AsyncExportButton extends Component
{
    public string $startDate;

    public string $endDate;

    public ?int $taskId = null;

    public function mount(string $startDate, string $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function requestExport(ReportService $reportService)
    {
        $start = Carbon::parse($this->startDate);
        $end = Carbon::parse($this->endDate);

        $task = $reportService->queueExport($start, $end, auth()->id());
        $this->taskId = $task->id;
    }

    public function render()
    {
        return view('livewire.async-export-button');
    }
}
