<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\TestCase;

class MassAssignmentHardeningTest extends TestCase
{
    /**
     * @var array<int, class-string>
     */
    protected array $models71 = [
        // Customers (8)
        \App\Modules\Customers\Models\Customer::class,
        \App\Modules\Customers\Models\CustomerAddress::class,
        \App\Modules\Customers\Models\CustomerContact::class,
        \App\Modules\Customers\Models\CustomerGroup::class,
        \App\Modules\Customers\Models\CustomerLoyaltyAccount::class,
        \App\Modules\Customers\Models\CustomerMergeLog::class,
        \App\Modules\Customers\Models\LoyaltyLedger::class,
        \App\Modules\Customers\Models\LoyaltyTier::class,

        // Finance (35)
        \App\Modules\Finance\Models\AccountPeriodBalance::class,
        \App\Modules\Finance\Models\AccountingPeriod::class,
        \App\Modules\Finance\Models\ApLedgerEntry::class,
        \App\Modules\Finance\Models\ApPayment::class,
        \App\Modules\Finance\Models\ApPaymentAllocation::class,
        \App\Modules\Finance\Models\ArReceipt::class,
        \App\Modules\Finance\Models\BankReconciliationMatch::class,
        \App\Modules\Finance\Models\BankReconciliationSession::class,
        \App\Modules\Finance\Models\BankStatement::class,
        \App\Modules\Finance\Models\BankStatementLine::class,
        \App\Modules\Finance\Models\CashBankAccount::class,
        \App\Modules\Finance\Models\CashBankTransaction::class,
        \App\Modules\Finance\Models\ChartOfAccount::class,
        \App\Modules\Finance\Models\Expense::class,
        \App\Modules\Finance\Models\ExpenseApproval::class,
        \App\Modules\Finance\Models\ExpenseAttachment::class,
        \App\Modules\Finance\Models\ExpenseCategory::class,
        \App\Modules\Finance\Models\ExpenseLine::class,
        \App\Modules\Finance\Models\FinancialEventOutbox::class,
        \App\Modules\Finance\Models\FixedAsset::class,
        \App\Modules\Finance\Models\FixedAssetCategory::class,
        \App\Modules\Finance\Models\FixedAssetDepreciationSchedule::class,
        \App\Modules\Finance\Models\FixedAssetDisposal::class,
        \App\Modules\Finance\Models\InventoryValuationSnapshot::class,
        \App\Modules\Finance\Models\JournalEntry::class,
        \App\Modules\Finance\Models\JournalLine::class,
        \App\Modules\Finance\Models\PeriodClosingChecklist::class,
        \App\Modules\Finance\Models\PeriodClosingLog::class,
        \App\Modules\Finance\Models\PostingRule::class,
        \App\Modules\Finance\Models\PostingRuleLine::class,
        \App\Modules\Finance\Models\PostingRuleVersion::class,
        \App\Modules\Finance\Models\TaxAccountMapping::class,
        \App\Modules\Finance\Models\TaxCode::class,
        \App\Modules\Finance\Models\TaxLedgerEntry::class,
        \App\Modules\Finance\Models\TaxRate::class,

        // HR (6)
        \App\Modules\HR\Models\Department::class,
        \App\Modules\HR\Models\Employee::class,
        \App\Modules\HR\Models\EmployeeBankAccount::class,
        \App\Modules\HR\Models\EmployeeBpjsProfile::class,
        \App\Modules\HR\Models\EmployeeTaxProfile::class,
        \App\Modules\HR\Models\JobTitle::class,

        // Inventory (2)
        \App\Modules\Inventory\Models\Ingredient::class,
        \App\Modules\Inventory\Models\IngredientStockMovement::class,

        // Purchasing (18)
        \App\Modules\Purchasing\Models\AccountingPayableOutbox::class,
        \App\Modules\Purchasing\Models\GoodsReceivedNote::class,
        \App\Modules\Purchasing\Models\GoodsReceivedNoteLine::class,
        \App\Modules\Purchasing\Models\PurchaseApprovalLog::class,
        \App\Modules\Purchasing\Models\PurchaseApprovalRequest::class,
        \App\Modules\Purchasing\Models\PurchaseApprovalRule::class,
        \App\Modules\Purchasing\Models\PurchaseOrder::class,
        \App\Modules\Purchasing\Models\PurchaseOrderLine::class,
        \App\Modules\Purchasing\Models\PurchaseOrderLinePrAllocation::class,
        \App\Modules\Purchasing\Models\PurchaseOrderRevision::class,
        \App\Modules\Purchasing\Models\PurchaseRequest::class,
        \App\Modules\Purchasing\Models\PurchaseRequestLine::class,
        \App\Modules\Purchasing\Models\Supplier::class,
        \App\Modules\Purchasing\Models\SupplierContact::class,
        \App\Modules\Purchasing\Models\SupplierInvoice::class,
        \App\Modules\Purchasing\Models\SupplierInvoiceGrnMatch::class,
        \App\Modules\Purchasing\Models\SupplierInvoiceLine::class,
        \App\Modules\Purchasing\Models\SupplierProduct::class,

        // Sales (2)
        \App\Modules\Sales\Models\CashDrawerMovement::class,
        \App\Modules\Sales\Models\Payment::class,
    ];

    public function test_all_71_models_declare_explicit_non_empty_fillable_arrays(): void
    {
        $this->assertCount(71, $this->models71, 'Must check exactly 71 models');

        foreach ($this->models71 as $modelClass) {
            $model = new $modelClass();
            $fillable = $model->getFillable();
            $guarded = $model->getGuarded();

            $this->assertNotEmpty(
                $fillable,
                "Model {$modelClass} must have a non-empty \$fillable array."
            );

            $this->assertNotEquals(
                ['id'],
                $guarded,
                "Model {$modelClass} must not declare protected \$guarded = ['id']."
            );

            $this->assertFalse(
                $model->isFillable('unauthorized_injected_parameter'),
                "Model {$modelClass} must reject arbitrary unfillable attributes."
            );
        }
    }

    public function test_mass_assignment_discards_unfillable_hostile_keys(): void
    {
        $customer = new \App\Modules\Customers\Models\Customer();
        $customer->fill([
            'name' => 'Valid Name',
            'unauthorized_root_bypass' => 'malicious_value',
            'id' => 999999,
        ]);

        $this->assertSame('Valid Name', $customer->name);
        $this->assertFalse(isset($customer->unauthorized_root_bypass));
        $this->assertNull($customer->id);
    }
}
