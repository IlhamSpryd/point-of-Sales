<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class AsyncExportButton extends Component
{
    public string $startDate;

    public string $endDate;

    public ?int $taskId = null;

    public function mount(string $startDate, string $endDate): void
    {
        // FIX: child Livewire components embedded via <livewire:x /> do
        // NOT inherit the parent route's middleware (role:Owner,Manager
        // on /reports/sales). Their AJAX updates go through Livewire's
        // own endpoint, so without this, any authenticated user --
        // regardless of role -- could invoke requestExport() directly.
        abort_unless(
            in_array(Auth::user()?->role?->name, ['Owner', 'Manager'], true),
            403
        );

        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function requestExport(ReportService $reportService): void
    {
        abort_unless(
            in_array(Auth::user()?->role?->name, ['Owner', 'Manager'], true),
            403
        );

        // Guard against a stray double-click (or forged repeat call)
        // creating duplicate ExportTask rows for the same request.
        if ($this->taskId !== null) {
            return;
        }

        try {
            $start = Carbon::parse($this->startDate);
            $end = Carbon::parse($this->endDate);

            $task = $reportService->queueExport($start, $end, Auth::id());
            $this->taskId = $task->id;
        } catch (ValidationException $e) {
            $this->addError('range', collect($e->errors())->flatten()->first());
        }
    }

    public function render()
    {
        return view('livewire.async-export-button');
    }
}
