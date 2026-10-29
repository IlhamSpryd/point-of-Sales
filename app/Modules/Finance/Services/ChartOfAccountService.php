<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Exceptions\UnresolvableAccountMappingException;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ChartOfAccountService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    public function createAccount(array $data, ?User $actor = null): ChartOfAccount
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        return DB::transaction(function () use ($data, $tenantId, $actor) {
            $accountType = $data['account_type'] instanceof AccountTypeEnum
                ? $data['account_type']
                : AccountTypeEnum::from($data['account_type']);

            $normalBalance = isset($data['normal_balance'])
                ? ($data['normal_balance'] instanceof NormalBalanceEnum ? $data['normal_balance'] : NormalBalanceEnum::from($data['normal_balance']))
                : $accountType->defaultNormalBalance();

            $parentId = $data['parent_id'] ?? null;
            $level = 1;
            if ($parentId !== null) {
                $parent = ChartOfAccount::where('id', $parentId)->where('tenant_id', $tenantId)->firstOrFail();
                $level = $parent->level + 1;
            }

            return ChartOfAccount::create([
                'tenant_id' => $tenantId,
                'account_code' => $data['account_code'],
                'account_name' => $data['account_name'],
                'account_type' => $accountType,
                'account_subtype' => $data['account_subtype'] ?? AccountSubtypeEnum::OTHER,
                'normal_balance' => $normalBalance,
                'system_account_key' => $data['system_account_key'] ?? null,
                'parent_id' => $parentId,
                'level' => $level,
                'is_postable' => $data['is_postable'] ?? true,
                'is_control_account' => $data['is_control_account'] ?? false,
                'allow_manual_journal' => $data['allow_manual_journal'] ?? true,
                'is_reconcilable' => $data['is_reconcilable'] ?? false,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $actor?->id,
            ]);
        });
    }

    public function updateAccount(int $id, array $data, ?User $actor = null): ChartOfAccount
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        return DB::transaction(function () use ($id, $data, $tenantId, $actor) {
            $account = ChartOfAccount::where('id', $id)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($account->hasTransactions()) {
                // Aturan Bisnis FIN-M7-08 / FIN-M7-10: Jika sudah ada mutasi, account_type dan normal_balance dilarang diubah
                if (isset($data['account_type'])) {
                    $newType = $data['account_type'] instanceof AccountTypeEnum
                        ? $data['account_type']
                        : AccountTypeEnum::from($data['account_type']);
                    if ($newType !== $account->account_type) {
                        throw new DomainException('FIN-M7-08: Tipe akun tidak dapat diubah karena akun telah memiliki transaksi jurnal.');
                    }
                }

                if (isset($data['normal_balance'])) {
                    $newBalance = $data['normal_balance'] instanceof NormalBalanceEnum
                        ? $data['normal_balance']
                        : NormalBalanceEnum::from($data['normal_balance']);
                    if ($newBalance !== $account->normal_balance) {
                        throw new DomainException('FIN-M7-08: Saldo normal tidak dapat diubah karena akun telah memiliki transaksi jurnal.');
                    }
                }
            }

            if (isset($data['account_name'])) {
                $account->account_name = $data['account_name'];
            }
            if (isset($data['account_code'])) {
                $account->account_code = $data['account_code'];
            }
            if (isset($data['system_account_key'])) {
                $account->system_account_key = $data['system_account_key'];
            }
            if (isset($data['is_postable'])) {
                $account->is_postable = (bool) $data['is_postable'];
            }
            if (isset($data['is_control_account'])) {
                $account->is_control_account = (bool) $data['is_control_account'];
            }
            if (isset($data['allow_manual_journal'])) {
                $account->allow_manual_journal = (bool) $data['allow_manual_journal'];
            }
            if (isset($data['is_reconcilable'])) {
                $account->is_reconcilable = (bool) $data['is_reconcilable'];
            }
            if (isset($data['is_active'])) {
                $account->is_active = (bool) $data['is_active'];
            }

            $account->updated_by = $actor?->id;
            $account->save();

            return $account;
        });
    }

    public function deactivateAccount(int $id, ?User $actor = null): ChartOfAccount
    {
        return $this->updateAccount($id, ['is_active' => false], $actor);
    }

    public function getAccountBySystemKey(string $key, ?int $tenantId = null): ChartOfAccount
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        $account = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('system_account_key', $key)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw UnresolvableAccountMappingException::forSystemKey($key);
        }

        return $account;
    }

    public function getAccountTree(?int $tenantId = null): Collection
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();

        return ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->orderBy('account_code')])
            ->orderBy('account_code')
            ->get();
    }
}
