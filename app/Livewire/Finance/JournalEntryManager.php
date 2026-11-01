<?php

declare(strict_types=1);

namespace App\Livewire\Finance;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\DataTransferObjects\ManualJournalDto;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\PostingEngineService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class JournalEntryManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    #[Locked]
    public string $workbenchIdempotencyKey = '';

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $statusFilter = '';

    #[Url(history: true)]
    public string $dateFrom = '';

    #[Url(history: true)]
    public string $dateTo = '';

    // Workbench modal state
    public bool $showWorkbenchModal = false;

    public string $postingDate = '';

    public ?int $storeId = null;

    public string $notes = '';

    public string $journalType = 'MANUAL_ADJUSTMENT';

    /**
     * @var array<int, array{account_id: int|string, debit_amount: string|float, credit_amount: string|float, description: string}>
     */
    public array $lines = [];

    // View detail modal
    public bool $showDetailModal = false;

    #[Locked]
    public ?int $selectedJournalId = null;

    public ?JournalEntry $selectedJournal = null;

    // Reversal modal
    public bool $showReversalModal = false;

    public string $reversalReason = '';

    public function mount(): void
    {
        $this->postingDate = now()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function openWorkbenchModal(): void
    {
        $this->authorize('create', JournalEntry::class);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $this->workbenchIdempotencyKey = 'manual_journal:'.$tenantId.':'.(string) Str::uuid();

        $this->resetValidation();
        $this->postingDate = now()->toDateString();
        $this->storeId = null;
        $this->notes = '';
        $this->journalType = 'MANUAL_ADJUSTMENT';

        // Inisialisasi minimal 2 baris (1 debit, 1 credit)
        $this->lines = [
            ['account_id' => '', 'debit_amount' => '0.00', 'credit_amount' => '0.00', 'description' => ''],
            ['account_id' => '', 'debit_amount' => '0.00', 'credit_amount' => '0.00', 'description' => ''],
        ];

        $this->showWorkbenchModal = true;
    }

    public function addLine(): void
    {
        $this->lines[] = [
            'account_id' => '',
            'debit_amount' => '0.00',
            'credit_amount' => '0.00',
            'description' => '',
        ];
    }

    public function removeLine(int $index): void
    {
        if (count($this->lines) > 2) {
            unset($this->lines[$index]);
            $this->lines = array_values($this->lines);
        }
    }

    /**
     * @return array{totalDebit: string, totalCredit: string, isBalanced: bool, difference: string}
     */
    public function getBalanceSummaryProperty(): array
    {
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($this->lines as $line) {
            $debit = bcadd((string) $line['debit_amount'], '0', 2);
            $credit = bcadd((string) $line['credit_amount'], '0', 2);

            $totalDebit = bcadd($totalDebit, $debit, 2);
            $totalCredit = bcadd($totalCredit, $credit, 2);
        }

        $diff = bcsub($totalDebit, $totalCredit, 2);
        $isBalanced = (bccomp($totalDebit, $totalCredit, 2) === 0) && (bccomp($totalDebit, '0.00', 2) > 0);

        return [
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'isBalanced' => $isBalanced,
            'difference' => $diff,
        ];
    }

    public function postManualJournal(PostingEngineService $postingEngine): void
    {
        $this->authorize('create', JournalEntry::class);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $this->validate([
            'postingDate' => ['required', 'date'],
            'notes' => ['required', 'string', 'max:500'],
            'journalType' => ['required', 'in:MANUAL_ADJUSTMENT,OPENING_BALANCE'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit_amount' => ['required', 'numeric', 'min:0'],
            'lines.*.credit_amount' => ['required', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        $balance = $this->getBalanceSummaryProperty();
        if (! $balance['isBalanced']) {
            $this->addError('lines', 'Jurnal tidak seimbang. Total Debit (Rp '.number_format((float) $balance['totalDebit'], 2).') wajib sama dengan Total Credit (Rp '.number_format((float) $balance['totalCredit'], 2).') dan lebih dari 0.');

            return;
        }

        // Single-sided line validation
        foreach ($this->lines as $idx => $line) {
            $debit = (float) $line['debit_amount'];
            $credit = (float) $line['credit_amount'];

            if (($debit > 0 && $credit > 0) || ($debit === 0.0 && $credit === 0.0)) {
                $this->addError("lines.{$idx}.debit_amount", 'Baris harus satu sisi (hanya salah satu Debit atau Credit yang bernilai positif).');

                return;
            }
        }

        /** @var User $actor */
        $actor = Auth::user();

        $lineDtos = array_map(function ($line) {
            return new JournalLineDto(
                accountId: (int) $line['account_id'],
                debitAmount: number_format((float) $line['debit_amount'], 2, '.', ''),
                creditAmount: number_format((float) $line['credit_amount'], 2, '.', ''),
                description: $line['description'] ?: null
            );
        }, $this->lines);

        if (empty($this->workbenchIdempotencyKey)) {
            $this->workbenchIdempotencyKey = 'manual_journal:'.$tenantId.':'.(string) Str::uuid();
        }

        $dto = new ManualJournalDto(
            tenantId: $tenantId,
            storeId: $this->storeId,
            postingDate: $this->postingDate,
            idempotencyKey: $this->workbenchIdempotencyKey,
            lines: $lineDtos,
            notes: trim($this->notes),
            currencyCode: 'IDR',
            journalType: $this->journalType
        );

        try {
            $journal = $postingEngine->postManualJournal($dto, $actor);
            session()->flash('success', "Jurnal {$journal->journal_number} berhasil diposting ke Buku Besar.");
            $this->showWorkbenchModal = false;
        } catch (DomainException $e) {
            $this->addError('lines', $e->getMessage());
        }
    }

    public function viewJournal(int $id): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $this->selectedJournal = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['lines.account', 'createdByUser'])
            ->findOrFail($id);

        $this->selectedJournalId = $this->selectedJournal->id;
        $this->showDetailModal = true;
    }

    public function openReversalModal(int $id): void
    {
        $this->resetValidation();
        $this->selectedJournalId = $id;
        $this->reversalReason = '';
        $this->showReversalModal = true;
    }

    public function confirmReversal(PostingEngineService $postingEngine): void
    {
        $this->validate([
            'reversalReason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $journal = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($this->selectedJournalId);

        $this->authorize('reverse', $journal);

        /** @var User $actor */
        $actor = Auth::user();

        try {
            $reversalJournal = $postingEngine->reverseJournal(
                original: $journal,
                reason: trim($this->reversalReason),
                actor: $actor
            );

            session()->flash('success', "Jurnal {$journal->journal_number} berhasil di-reverse dengan jurnal pembalik {$reversalJournal->journal_number}.");
            $this->showReversalModal = false;
            $this->showDetailModal = false;
        } catch (DomainException $e) {
            $this->addError('reversalReason', $e->getMessage());
        }
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $query = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['lines.account']);

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('journal_number', 'like', $term)
                    ->orWhere('notes', 'like', $term)
                    ->orWhere('source_number', 'like', $term);
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->dateFrom !== '') {
            $query->whereDate('posting_date', '>=', $this->dateFrom);
        }

        if ($this->dateTo !== '') {
            $query->whereDate('posting_date', '<=', $this->dateTo);
        }

        $journals = $query->orderByDesc('posting_date')->orderByDesc('id')->paginate(15);

        $accounts = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->orderBy('account_code')
            ->get();

        return view('livewire.finance.journal-entry-manager', [
            'journals' => $journals,
            'accounts' => $accounts,
            'statuses' => JournalStatusEnum::cases(),
        ]);
    }
}
