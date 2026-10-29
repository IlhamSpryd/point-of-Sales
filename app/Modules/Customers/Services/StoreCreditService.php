<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Models\Customer;
use App\Models\User;
use App\Modules\Customers\Enums\StoreCreditLedgerTypeEnum;
use App\Modules\Customers\Enums\StoreCreditStatusEnum;
use App\Modules\Customers\Models\StoreCreditAccount;
use App\Modules\Customers\Models\StoreCreditLedger;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreCreditService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Tambahkan saldo Store Credit (Deposit / Credit Note / Overpayment).
     */
    public function issueStoreCredit(
        Customer $customer,
        string $amount,
        string|StoreCreditLedgerTypeEnum $entryType = StoreCreditLedgerTypeEnum::CREDIT_NOTE_ISSUE,
        ?int $storeId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?User $actor = null,
        ?string $idempotencyKey = null
    ): StoreCreditLedger {
        $tenantId = (int) ($customer->tenant_id ?? $this->tenantContext->requireTenantId());
        $issueAmount = bcadd($amount, '0', 2);

        if (bccomp($issueAmount, '0.00', 2) <= 0) {
            throw new DomainException('Jumlah penambahan saldo Store Credit harus lebih besar dari 0.');
        }

        $typeEnum = $entryType instanceof StoreCreditLedgerTypeEnum
            ? $entryType
            : StoreCreditLedgerTypeEnum::from($entryType);

        return DB::transaction(function () use (
            $tenantId,
            $customer,
            $issueAmount,
            $typeEnum,
            $storeId,
            $referenceType,
            $referenceId,
            $actor,
            $idempotencyKey
        ) {
            // Lock or create account
            $account = StoreCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                $account = StoreCreditAccount::create([
                    'tenant_id' => $tenantId,
                    'customer_id' => $customer->id,
                    'current_balance' => '0.00',
                    'status' => StoreCreditStatusEnum::ACTIVE,
                ]);

                $account = StoreCreditAccount::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $account->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $newBalance = bcadd((string) $account->current_balance, $issueAmount, 2);
            $account->current_balance = $newBalance;
            $account->save();

            return StoreCreditLedger::create([
                'tenant_id' => $tenantId,
                'store_credit_account_id' => $account->id,
                'customer_id' => $customer->id,
                'store_id' => $storeId,
                'entry_type' => $typeEnum,
                'amount' => $issueAmount,
                'balance_after' => $newBalance,
                'reference_type' => $referenceType ?: 'MANUAL_DEPOSIT',
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey ?: 'sc_issue:'.$account->id.':'.(string) Str::uuid(),
                'actor_user_id' => $actor?->id,
                'created_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Potong saldo Store Credit untuk pembayaran (Pesanan / Invoice AR).
     */
    public function redeemStoreCredit(
        Customer $customer,
        string $amount,
        string|StoreCreditLedgerTypeEnum $entryType = StoreCreditLedgerTypeEnum::REDEEM_ORDER,
        ?int $storeId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?User $actor = null,
        ?string $idempotencyKey = null
    ): StoreCreditLedger {
        $tenantId = (int) ($customer->tenant_id ?? $this->tenantContext->requireTenantId());
        $redeemAmount = bcadd($amount, '0', 2);

        if (bccomp($redeemAmount, '0.00', 2) <= 0) {
            throw new DomainException('Jumlah pemotongan saldo Store Credit harus lebih besar dari 0.');
        }

        $typeEnum = $entryType instanceof StoreCreditLedgerTypeEnum
            ? $entryType
            : StoreCreditLedgerTypeEnum::from($entryType);

        return DB::transaction(function () use (
            $tenantId,
            $customer,
            $redeemAmount,
            $typeEnum,
            $storeId,
            $referenceType,
            $referenceId,
            $actor,
            $idempotencyKey
        ) {
            /** @var StoreCreditAccount|null $account */
            $account = StoreCreditAccount::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if (! $account || ! $account->isActive()) {
                throw new DomainException('Akun Store Credit pelanggan tidak aktif atau belum terdaftar.');
            }

            if (bccomp((string) $account->current_balance, $redeemAmount, 2) < 0) {
                throw new DomainException("Saldo Store Credit tidak mencukupi. Saldo saat ini: Rp {$account->current_balance}, dibutuhkan: Rp {$redeemAmount}.");
            }

            $newBalance = bcsub((string) $account->current_balance, $redeemAmount, 2);
            $account->current_balance = $newBalance;
            $account->save();

            return StoreCreditLedger::create([
                'tenant_id' => $tenantId,
                'store_credit_account_id' => $account->id,
                'customer_id' => $customer->id,
                'store_id' => $storeId,
                'entry_type' => $typeEnum,
                'amount' => '-'.$redeemAmount,
                'balance_after' => $newBalance,
                'reference_type' => $referenceType ?: 'ORDER_PAYMENT',
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey ?: 'sc_redeem:'.$account->id.':'.(string) Str::uuid(),
                'actor_user_id' => $actor?->id,
                'created_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Dapatkan saldo Store Credit pelanggan saat ini.
     */
    public function getBalance(Customer $customer): string
    {
        $tenantId = (int) ($customer->tenant_id ?? $this->tenantContext->requireTenantId());

        $account = StoreCreditAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->first();

        return $account ? (string) $account->current_balance : '0.00';
    }
}
