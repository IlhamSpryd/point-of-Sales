<?php

declare(strict_types=1);

namespace App\Livewire\Finance;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\User;
use App\Modules\Finance\Enums\BankReconciliationSessionStatusEnum;
use App\Modules\Finance\Enums\BankStatementLineMatchStatusEnum;
use App\Modules\Finance\Models\BankReconciliationMatch;
use App\Modules\Finance\Models\BankReconciliationSession;
use App\Modules\Finance\Models\BankStatementLine;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\CashBankTransaction;
use App\Modules\Finance\Services\BankReconciliationService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class BankReconciliationWorkbench extends Component
{
    use RequiresTenantContext;

    #[Url(history: true)]
    public ?int $selectedAccountId = null;

    #[Url(history: true)]
    public ?int $selectedSessionId = null;

    // Start Session Modal
    public bool $showStartSessionModal = false;

    public string $statementEndDate = '';

    public string $statementEndingBalance = '0.00';

    public string $sessionNotes = '';

    // Manual match selection
    public ?int $selectedStatementLineId = null;

    public ?int $selectedTransactionId = null;

    public function mount(): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        if (! $this->selectedAccountId) {
            $firstAccount = CashBankAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();
            $this->selectedAccountId = $firstAccount?->id;
        }

        if ($this->selectedAccountId && ! $this->selectedSessionId) {
            $activeSession = BankReconciliationSession::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('cash_bank_account_id', $this->selectedAccountId)
                ->where('status', BankReconciliationSessionStatusEnum::IN_PROGRESS)
                ->latest('id')
                ->first();
            $this->selectedSessionId = $activeSession?->id;
        }

        $this->statementEndDate = now()->toDateString();
    }

    public function updatedSelectedAccountId(): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $activeSession = BankReconciliationSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('cash_bank_account_id', $this->selectedAccountId)
            ->where('status', BankReconciliationSessionStatusEnum::IN_PROGRESS)
            ->latest('id')
            ->first();
        $this->selectedSessionId = $activeSession?->id;
        $this->resetMatchSelection();
    }

    public function resetMatchSelection(): void
    {
        $this->selectedStatementLineId = null;
        $this->selectedTransactionId = null;
    }

    public function openStartSessionModal(): void
    {
        $this->resetValidation();
        $this->statementEndDate = now()->toDateString();
        $this->statementEndingBalance = '0.00';
        $this->sessionNotes = '';
        $this->showStartSessionModal = true;
    }

    public function startNewSession(BankReconciliationService $service): void
    {
        $this->validate([
            'selectedAccountId' => ['required', 'integer'],
            'statementEndDate' => ['required', 'date'],
            'statementEndingBalance' => ['required', 'numeric'],
            'sessionNotes' => ['nullable', 'string', 'max:255'],
        ]);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $account = CashBankAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->selectedAccountId);

        $this->authorize('reconcile', $account);

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $session = $service->startSession(
                account: $account,
                endDate: $this->statementEndDate,
                statementEndingBalance: number_format((float) $this->statementEndingBalance, 2, '.', ''),
                actor: $actor,
                notes: $this->sessionNotes ?: null
            );

            $this->selectedSessionId = $session->id;
            $this->showStartSessionModal = false;
            session()->flash('success', "Reconciliation session {$session->session_number} started successfully.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function runAutoMatch(BankReconciliationService $service): void
    {
        if (! $this->selectedSessionId) {
            return;
        }

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $session = BankReconciliationSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->selectedSessionId);

        $this->authorize('reconcile', $session->account);

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $matches = $service->autoMatch($session, $actor);
            $count = count($matches);
            session()->flash('success', "Auto-match matched {$count} transactions successfully.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function manualMatch(BankReconciliationService $service): void
    {
        if (! $this->selectedSessionId || ! $this->selectedStatementLineId || ! $this->selectedTransactionId) {
            session()->flash('error', 'Please select one statement line and one cash/bank transaction to match.');

            return;
        }

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $session = BankReconciliationSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->selectedSessionId);

        $this->authorize('reconcile', $session->account);

        $statementLine = BankStatementLine::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->selectedStatementLineId);

        $transaction = CashBankTransaction::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->selectedTransactionId);

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $service->manualMatch($session, $statementLine, $transaction, $actor);
            $this->resetMatchSelection();
            session()->flash('success', 'Transaction lines matched successfully.');
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function unmatch(int $matchId, BankReconciliationService $service): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $match = BankReconciliationMatch::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($matchId);

        $this->authorize('reconcile', $match->session->account);

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $service->unmatch($match, $actor);
            session()->flash('success', 'Match unlinked successfully.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function completeSession(BankReconciliationService $service): void
    {
        if (! $this->selectedSessionId) {
            return;
        }

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $session = BankReconciliationSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->selectedSessionId);

        $this->authorize('reconcile', $session->account);

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $service->completeSession($session, $actor);
            session()->flash('success', "Reconciliation session {$session->session_number} completed successfully.");
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(BankReconciliationService $service): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $accounts = CashBankAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('account_code')
            ->get();

        $session = null;
        $unreconciledStatementLines = collect();
        $unreconciledTransactions = collect();
        $matches = collect();
        $difference = '0.00';

        if ($this->selectedSessionId) {
            $session = BankReconciliationSession::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->with(['account', 'matches.statementLine', 'matches.transaction'])
                ->find($this->selectedSessionId);

            if ($session) {
                $difference = $service->calculateUnreconciledDifference($session);

                $unreconciledStatementLines = BankStatementLine::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('cash_bank_account_id', $session->cash_bank_account_id)
                    ->where('match_status', BankStatementLineMatchStatusEnum::UNMATCHED)
                    ->orderBy('value_date')
                    ->get();

                $unreconciledTransactions = CashBankTransaction::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('cash_bank_account_id', $session->cash_bank_account_id)
                    ->where('is_reconciled', false)
                    ->orderBy('transaction_date')
                    ->get();

                $matches = $session->matches;
            }
        }

        $sessionsList = BankReconciliationSession::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($this->selectedAccountId, fn ($q) => $q->where('cash_bank_account_id', $this->selectedAccountId))
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('livewire.finance.bank-reconciliation-workbench', [
            'accounts' => $accounts,
            'session' => $session,
            'sessionsList' => $sessionsList,
            'unreconciledStatementLines' => $unreconciledStatementLines,
            'unreconciledTransactions' => $unreconciledTransactions,
            'matches' => $matches,
            'difference' => $difference,
        ]);
    }
}
