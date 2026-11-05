<?php

declare(strict_types=1);

namespace App\Livewire\HR;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Store;
use App\Modules\HR\Enums\AttendanceStatusEnum;
use App\Modules\HR\Models\AttendanceRecord;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class AttendanceMonitor extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public ?int $selectedStoreId = null;

    public string $dateFilter = '';

    public string $statusFilter = 'ALL';

    // Correction Modal
    public bool $isCorrectionModalOpen = false;

    public ?int $editingRecordId = null;

    public string $correctedClockIn = '';

    public string $correctedClockOut = '';

    public string $correctionReason = '';

    public function mount(): void
    {
        $this->dateFilter = now()->toDateString();
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $defaultStore = Store::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        $this->selectedStoreId = $defaultStore?->id;
    }

    public function openCorrectionModal(int $recordId): void
    {
        $this->authorize('update', AttendanceRecord::class);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var AttendanceRecord|null $record */
        $record = AttendanceRecord::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $recordId)
            ->first();

        if (! $record) {
            return;
        }

        $this->editingRecordId = $record->id;
        $this->correctedClockIn = $record->clock_in_at->format('Y-m-d\TH:i');
        $this->correctedClockOut = $record->clock_out_at ? $record->clock_out_at->format('Y-m-d\TH:i') : '';
        $this->correctionReason = '';
        $this->isCorrectionModalOpen = true;
    }

    public function closeCorrectionModal(): void
    {
        $this->isCorrectionModalOpen = false;
    }

    public function saveCorrection(): void
    {
        $this->authorize('update', AttendanceRecord::class);

        if (! $this->editingRecordId || empty($this->correctionReason)) {
            session()->flash('error', 'Alasan koreksi absensi wajib diisi.');

            return;
        }

        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var AttendanceRecord|null $record */
        $record = AttendanceRecord::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $this->editingRecordId)
            ->first();

        if ($record) {
            $this->authorize('update', $record);

            $record->clock_in_at = ! empty($this->correctedClockIn) ? Carbon::parse($this->correctedClockIn) : null;
            $record->clock_out_at = ! empty($this->correctedClockOut) ? Carbon::parse($this->correctedClockOut) : null;
            $prefix = (is_string($record->notes) && $record->notes !== '') ? $record->notes.' | ' : '';
            $record->notes = $prefix.'Koreksi: '.$this->correctionReason;
            $record->save();

            session()->flash('success', 'Koreksi waktu absensi berhasil disimpan.');
        }

        $this->isCorrectionModalOpen = false;
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        /** @var Collection<int, Store> $stores */
        $stores = Store::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get();

        $query = AttendanceRecord::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with(['employee'])
            ->orderBy('id', 'desc');

        if ($this->selectedStoreId) {
            $query->where('store_id', $this->selectedStoreId);
        }

        if (! empty($this->dateFilter)) {
            $date = Carbon::parse($this->dateFilter);
            $query->whereBetween('clock_in_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);
        }

        if ($this->statusFilter !== 'ALL') {
            $query->where('status', AttendanceStatusEnum::from($this->statusFilter));
        }

        $records = $query->paginate(15);

        return view('livewire.hr.attendance-monitor', [
            'stores' => $stores,
            'records' => $records,
        ])->layout('layouts.app', ['title' => 'Pemantauan Absensi & Lembur — Apeiron']);
    }
}
