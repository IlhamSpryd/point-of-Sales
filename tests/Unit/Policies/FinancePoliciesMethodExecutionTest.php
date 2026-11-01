<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Modules\Customers\Models\GiftCard;
use App\Modules\Customers\Models\StoreCreditAccount;
use App\Modules\Customers\Models\Voucher;
use App\Modules\Finance\Enums\ApPaymentStatusEnum;
use App\Modules\Finance\Enums\ExpenseStatusEnum;
use App\Modules\Finance\Enums\FixedAssetStatusEnum;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\ApPayment;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\FixedAsset;
use App\Modules\Finance\Models\JournalEntry;
use App\Policies\AccountingPeriodPolicy;
use App\Policies\ApPaymentPolicy;
use App\Policies\CashBankAccountPolicy;
use App\Policies\ChartOfAccountPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\FixedAssetPolicy;
use App\Policies\GiftCardPolicy;
use App\Policies\JournalEntryPolicy;
use App\Policies\StoreCreditPolicy;
use App\Policies\VoucherPolicy;
use PHPUnit\Framework\TestCase;

class FinancePoliciesMethodExecutionTest extends TestCase
{
    private function createMockUser(int $tenantId = 1, ?string $roleName = 'Owner', bool $isAdmin = false): User
    {
        $roleMock = null;
        if ($roleName !== null) {
            $roleMock = new class($roleName, $isAdmin)
            {
                public function __construct(public string $name, public bool $is_admin) {}

                public function hasPermission(string $permission): bool
                {
                    return true;
                }
            };
        }

        $user = new class($tenantId, $isAdmin, $roleMock) extends User
        {
            public $id = 1;

            public function __construct(
                public $tenant_id,
                public $is_admin,
                private $mockRole
            ) {}

            public function hasPermission(string $permission): bool
            {
                return $this->mockRole ? $this->mockRole->hasPermission($permission) : false;
            }

            public function getRoleAttribute()
            {
                return $this->mockRole;
            }

            public function relationLoaded($relation)
            {
                return $relation === 'role';
            }
        };

        return $user;
    }

    public function test_journal_entry_policy_executes_without_bad_method_call(): void
    {
        $policy = new JournalEntryPolicy;
        $user = $this->createMockUser();

        $journal = new JournalEntry;
        $journal->tenant_id = 1;
        $journal->created_by_user_id = 2;

        $this->assertIsBool($policy->viewAny($user));
        $this->assertIsBool($policy->view($user, $journal));
        $this->assertIsBool($policy->create($user));
        $this->assertIsBool($policy->approve($user, $journal));
        $this->assertIsBool($policy->post($user, $journal));
        $this->assertIsBool($policy->reverse($user, $journal));
    }

    public function test_chart_of_account_policy_executes_without_bad_method_call(): void
    {
        $policy = new ChartOfAccountPolicy;
        $user = $this->createMockUser();

        $account = new ChartOfAccount;
        $account->tenant_id = 1;

        $this->assertIsBool($policy->viewAny($user));
        $this->assertIsBool($policy->view($user, $account));
        $this->assertIsBool($policy->create($user));
        $this->assertIsBool($policy->update($user, $account));
    }

    public function test_accounting_period_policy_executes_without_bad_method_call(): void
    {
        $policy = new AccountingPeriodPolicy;
        $user = $this->createMockUser();

        $period = new AccountingPeriod;
        $period->tenant_id = 1;

        $this->assertIsBool($policy->viewAny($user));
        $this->assertIsBool($policy->view($user, $period));
        $this->assertIsBool($policy->manage($user, $period));
        $this->assertIsBool($policy->close($user, $period));
        $this->assertIsBool($policy->reopen($user, $period));
    }

    public function test_ap_payment_policy_executes_without_bad_method_call(): void
    {
        $policy = new ApPaymentPolicy;
        $user = $this->createMockUser();

        $payment = new ApPayment;
        $payment->tenant_id = 1;
        $payment->status = ApPaymentStatusEnum::DRAFT;

        $this->assertIsBool($policy->viewAny($user));
        $this->assertIsBool($policy->view($user, $payment));
        $this->assertIsBool($policy->create($user));
        $this->assertIsBool($policy->update($user, $payment));
        $this->assertIsBool($policy->approve($user, $payment));
        $this->assertIsBool($policy->post($user, $payment));
        $this->assertIsBool($policy->void($user, $payment));
    }

    public function test_expense_policy_executes_without_bad_method_call(): void
    {
        $policy = new ExpensePolicy;
        $user = $this->createMockUser();

        $expense = new Expense;
        $expense->tenant_id = 1;
        $expense->status = ExpenseStatusEnum::DRAFT;
        $expense->submitted_by_user_id = 2;

        $this->assertIsBool($policy->viewAny($user));
        $this->assertIsBool($policy->view($user, $expense));
        $this->assertIsBool($policy->create($user));
        $this->assertIsBool($policy->update($user, $expense));
        $this->assertIsBool($policy->delete($user, $expense));
        $this->assertIsBool($policy->submit($user, $expense));

        $expense->status = ExpenseStatusEnum::SUBMITTED;
        $this->assertIsBool($policy->approve($user, $expense));
        $this->assertIsBool($policy->reject($user, $expense));

        $expense->status = ExpenseStatusEnum::APPROVED;
        $this->assertIsBool($policy->recordPayment($user, $expense));
        $this->assertIsBool($policy->void($user, $expense));
    }

    public function test_fixed_asset_policy_executes_without_bad_method_call(): void
    {
        $policy = new FixedAssetPolicy;
        $user = $this->createMockUser();

        $asset = new FixedAsset;
        $asset->tenant_id = 1;
        $asset->status = FixedAssetStatusEnum::DRAFT;

        $this->assertIsBool($policy->viewAny($user));
        $this->assertIsBool($policy->view($user, $asset));
        $this->assertIsBool($policy->create($user));
        $this->assertIsBool($policy->update($user, $asset));
        $this->assertIsBool($policy->delete($user, $asset));
        $this->assertIsBool($policy->capitalize($user, $asset));

        $asset->status = FixedAssetStatusEnum::ACTIVE;
        $this->assertIsBool($policy->dispose($user, $asset));
    }

    public function test_cash_bank_account_policy_executes_without_bad_method_call(): void
    {
        $policy = new CashBankAccountPolicy;
        $user = $this->createMockUser();

        $account = new CashBankAccount;
        $account->tenant_id = 1;

        $this->assertIsBool($policy->viewAny($user));
        $this->assertIsBool($policy->view($user, $account));
        $this->assertIsBool($policy->create($user));
        $this->assertIsBool($policy->update($user, $account));
        $this->assertIsBool($policy->transfer($user, $account));
        $this->assertIsBool($policy->reconcile($user, $account));
    }

    public function test_store_credit_policy_executes_without_bad_method_call(): void
    {
        $policy = new StoreCreditPolicy;
        $user = $this->createMockUser();

        $account = new StoreCreditAccount;
        $account->tenant_id = 1;

        $this->assertIsBool($policy->viewAny($user));
        $this->assertIsBool($policy->view($user, $account));
        $this->assertIsBool($policy->create($user));
    }

    public function test_gift_card_policy_executes_without_bad_method_call(): void
    {
        $policy = new GiftCardPolicy;
        $user = $this->createMockUser();

        $giftCard = new GiftCard;
        $giftCard->tenant_id = 1;

        $this->assertIsBool($policy->viewAny($user));
        $this->assertIsBool($policy->view($user, $giftCard));
        $this->assertIsBool($policy->create($user));
        $this->assertIsBool($policy->void($user, $giftCard));
    }

    public function test_voucher_policy_executes_without_bad_method_call(): void
    {
        $policy = new VoucherPolicy;
        $user = $this->createMockUser();

        $voucher = new Voucher;
        $voucher->tenant_id = 1;

        $this->assertIsBool($policy->viewAny($user));
        $this->assertIsBool($policy->view($user, $voucher));
        $this->assertIsBool($policy->create($user));
        $this->assertIsBool($policy->update($user, $voucher));
        $this->assertIsBool($policy->delete($user, $voucher));
    }
}
