<?php

declare(strict_types=1);

namespace App\Livewire\Finance;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\User;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Services\AccountingPeriodService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class AccountingPeriodManager extends Component
{
    use RequiresTenantContext;

    #[Url(history: true)]
    public int $selectedFiscalYear = 2026;

    public bool $showReopenModal = false;

    #[Locked]
    public ?int $selectedPeriodId = null;

    public string $reopenReason = '';

    public bool $showGatesModal = false;

    public ?AccountingPeriod $gatesPeriod = null;

    /**
     * @var array<string, mixed>
     */
    public array $gatesResults = [];

    public function mount(): void
    {
        if (! $this->selectedFiscalYear) {
            $this->selectedFiscalYear = (int) now()->format('Y');
        }
    }

    public function generateFiscalYearPeriods(AccountingPeriodService $service): void
    {
        $this->authorize('manage', AccountingPeriod::class);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        try {
            $service->createFiscalYearPeriods($this->selectedFiscalYear, 1, $tenantId);
            session()->flash('success', "12 Periode fiskal untuk tahun {$this->selectedFiscalYear} berhasil dibuat.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function softClose(int $periodId, AccountingPeriodService $service): void
    {
        $this->authorize('close', AccountingPeriod::class);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $period = AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($periodId);

        $this->authorize('close', $period);

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $period = $service->softClosePeriod($periodId, $actor);
            session()->flash('success', "Periode {$period->period_code} berhasil diset ke status SOFT_CLOSED.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function hardClose(int $periodId, AccountingPeriodService $service): void
    {
        $this->authorize('close', AccountingPeriod::class);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $period = AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($periodId);

        $this->authorize('close', $period);

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $period = $service->hardClosePeriod($periodId, $actor);
            session()->flash('success', "Periode {$period->period_code} berhasil ditutup penuh (CLOSED).");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function lock(int $periodId, AccountingPeriodService $service): void
    {
        $this->authorize('close', AccountingPeriod::class);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $period = AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($periodId);

        $this->authorize('close', $period);

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $period = $service->lockPeriod($periodId, $actor);
            session()->flash('success', "Periode {$period->period_code} berhasil dikunci permanen (LOCKED).");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function openReopenModal(int $periodId): void
    {
        $this->authorize('reopen', AccountingPeriod::class);

        $this->resetValidation();
        $this->selectedPeriodId = $periodId;
        $this->reopenReason = '';
        $this->showReopenModal = true;
    }

    public function confirmReopen(AccountingPeriodService $service): void
    {
        $this->authorize('reopen', AccountingPeriod::class);

        $this->validate([
            'reopenReason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $period = AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->selectedPeriodId);

        $this->authorize('reopen', $period);

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $period = $service->reopenPeriod($this->selectedPeriodId, trim($this->reopenReason), $actor);
            session()->flash('success', "Periode {$period->period_code} berhasil dibuka kembali (OPEN).");
            $this->showReopenModal = false;
        } catch (DomainException $e) {
            $this->addError('reopenReason', $e->getMessage());
        }
    }

    public function evaluateGates(int $periodId, AccountingPeriodService $service): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $this->gatesPeriod = AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($periodId);

        /** @var User $actor */
        $actor = Auth::user();

        $this->gatesResults = $service->evaluateClosingGates($this->gatesPeriod, $actor);
        $this->showGatesModal = true;
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $periods = AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('fiscal_year', $this->selectedFiscalYear)
            ->orderBy('period_number')
            ->get();

        $availableYears = AccountingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->distinct()
            ->pluck('fiscal_year')
            ->toArray();

        if (! in_array($this->selectedFiscalYear, $availableYears, true)) {
            $availableYears[] = $this->selectedFiscalYear;
            sort($availableYears);
        }

        return view('livewire.finance.accounting-period-manager', [
            'periods' => $periods,
            'availableYears' => $availableYears,
        ]);
    }
}
