<?php

declare(strict_types=1);

namespace App\Livewire\Finance;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\User;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Services\ChartOfAccountService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ChartOfAccountManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $typeFilter = '';

    #[Url(history: true)]
    public string $statusFilter = '';

    public bool $showFormModal = false;

    #[Locked]
    public ?int $editingAccountId = null;

    public string $accountCode = '';

    public string $accountName = '';

    public string $accountType = 'ASSET';

    public string $normalBalance = 'DEBIT';

    public ?int $parentId = null;

    public bool $isPostable = true;

    public bool $allowManualJournal = true;

    public bool $isControlAccount = false;

    public bool $isReconcilable = false;

    public bool $isActive = true;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAccountType(string $value): void
    {
        try {
            $type = AccountTypeEnum::from($value);
            $this->normalBalance = $type->defaultNormalBalance()->value;
        } catch (\Throwable) {
            // keep existing normal balance
        }
    }

    public function openCreateModal(?int $parentId = null): void
    {
        $this->authorize('create', ChartOfAccount::class);

        $this->resetValidation();
        $this->editingAccountId = null;
        $this->accountCode = '';
        $this->accountName = '';
        $this->accountType = 'ASSET';
        $this->normalBalance = 'DEBIT';
        $this->parentId = $parentId;
        $this->isPostable = true;
        $this->allowManualJournal = true;
        $this->isControlAccount = false;
        $this->isReconcilable = false;
        $this->isActive = true;

        if ($parentId !== null) {
            $tenantId = (int) app(TenantContext::class)->requireTenantId();
            $parent = ChartOfAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->find($parentId);
            if ($parent) {
                $this->accountType = $parent->account_type->value;
                $this->normalBalance = $parent->normal_balance->value;
            }
        }

        $this->showFormModal = true;
    }

    public function openEditModal(int $id): void
    {
        $this->resetValidation();
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $account = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $this->editingAccountId = $account->id;
        $this->accountCode = $account->account_code;
        $this->accountName = $account->account_name;
        $this->accountType = $account->account_type->value;
        $this->normalBalance = $account->normal_balance->value;
        $this->parentId = $account->parent_id;
        $this->isPostable = (bool) $account->is_postable;
        $this->allowManualJournal = (bool) $account->allow_manual_journal;
        $this->isControlAccount = (bool) $account->is_control_account;
        $this->isReconcilable = (bool) $account->is_reconcilable;
        $this->isActive = (bool) $account->is_active;

        $this->showFormModal = true;
    }

    public function saveAccount(ChartOfAccountService $service): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $this->validate([
            'accountCode' => [
                'required',
                'string',
                'max:50',
                function ($attribute, $value, $fail) use ($tenantId) {
                    $query = ChartOfAccount::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('account_code', $value);
                    if ($this->editingAccountId) {
                        $query->where('id', '!=', $this->editingAccountId);
                    }
                    if ($query->exists()) {
                        $fail('Kode akun sudah digunakan.');
                    }
                },
            ],
            'accountName' => ['required', 'string', 'max:255'],
            'accountType' => ['required', 'string'],
            'normalBalance' => ['required', 'in:DEBIT,CREDIT'],
            'parentId' => ['nullable', 'integer'],
            'isPostable' => ['boolean'],
            'allowManualJournal' => ['boolean'],
            'isControlAccount' => ['boolean'],
            'isReconcilable' => ['boolean'],
            'isActive' => ['boolean'],
        ]);

        /** @var User|null $actor */
        $actor = Auth::user();

        $payload = [
            'account_code' => trim($this->accountCode),
            'account_name' => trim($this->accountName),
            'account_type' => AccountTypeEnum::from($this->accountType),
            'normal_balance' => NormalBalanceEnum::from($this->normalBalance),
            'parent_id' => $this->parentId,
            'is_postable' => $this->isPostable,
            'allow_manual_journal' => $this->allowManualJournal,
            'is_control_account' => $this->isControlAccount,
            'is_reconcilable' => $this->isReconcilable,
            'is_active' => $this->isActive,
        ];

        if ($this->editingAccountId) {
            $account = ChartOfAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->findOrFail($this->editingAccountId);
            $this->authorize('update', $account);
            $service->updateAccount($this->editingAccountId, $payload, $actor);
            session()->flash('success', 'Account updated successfully.');
        } else {
            $this->authorize('create', ChartOfAccount::class);
            $service->createAccount($payload, $actor);
            session()->flash('success', 'New account created successfully.');
        }

        $this->showFormModal = false;
    }

    public function toggleActive(int $id, ChartOfAccountService $service): void
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        $account = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $this->authorize('update', $account);

        /** @var User|null $actor */
        $actor = Auth::user();

        $service->updateAccount($id, ['is_active' => ! $account->is_active], $actor);
        session()->flash('success', 'Account status updated successfully.');
    }

    public function render(): View
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();

        $query = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['parent', 'children']);

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('account_code', 'like', $term)
                    ->orWhere('account_name', 'like', $term);
            });
        }

        if ($this->typeFilter !== '') {
            $query->where('account_type', $this->typeFilter);
        }

        if ($this->statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        $accounts = $query->orderBy('account_code')->paginate(20);

        $parentOptions = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_postable', false)
            ->orderBy('account_code')
            ->get();

        return view('livewire.finance.chart-of-account-manager', [
            'accounts' => $accounts,
            'parentOptions' => $parentOptions,
            'accountTypes' => AccountTypeEnum::cases(),
        ]);
    }
}
