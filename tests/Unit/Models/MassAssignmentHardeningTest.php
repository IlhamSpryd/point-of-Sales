<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerContact;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Models\CustomerMergeLog;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\AccountPeriodBalance;
use App\Modules\Finance\Models\ApLedgerEntry;
use App\Modules\Finance\Models\ApPayment;
use App\Modules\Finance\Models\ApPaymentAllocation;
use App\Modules\Finance\Models\ArReceipt;
use App\Modules\Finance\Models\BankReconciliationMatch;
use App\Modules\Finance\Models\BankReconciliationSession;
use App\Modules\Finance\Models\BankStatement;
use App\Modules\Finance\Models\BankStatementLine;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\CashBankTransaction;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseApproval;
use App\Modules\Finance\Models\ExpenseAttachment;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\ExpenseLine;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Models\FixedAsset;
use App\Modules\Finance\Models\FixedAssetCategory;
use App\Modules\Finance\Models\FixedAssetDepreciationSchedule;
use App\Modules\Finance\Models\FixedAssetDisposal;
use App\Modules\Finance\Models\InventoryValuationSnapshot;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Models\PeriodClosingChecklist;
use App\Modules\Finance\Models\PeriodClosingLog;
use App\Modules\Finance\Models\PostingRule;
use App\Modules\Finance\Models\PostingRuleLine;
use App\Modules\Finance\Models\PostingRuleVersion;
use App\Modules\Finance\Models\TaxAccountMapping;
use App\Modules\Finance\Models\TaxCode;
use App\Modules\Finance\Models\TaxLedgerEntry;
use App\Modules\Finance\Models\TaxRate;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\IngredientStockMovement;
use App\Modules\Pricing\Models\CustomerLoyaltyAccount;
use App\Modules\Pricing\Models\LoyaltyLedger;
use App\Modules\Pricing\Models\LoyaltyTier;
use App\Modules\Purchasing\Models\AccountingPayableOutbox;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\GoodsReceivedNoteLine;
use App\Modules\Purchasing\Models\PurchaseApprovalLog;
use App\Modules\Purchasing\Models\PurchaseApprovalRequest;
use App\Modules\Purchasing\Models\PurchaseApprovalRule;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\PurchaseOrderLinePrAllocation;
use App\Modules\Purchasing\Models\PurchaseOrderRevision;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierContact;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Purchasing\Models\SupplierInvoiceGrnMatch;
use App\Modules\Purchasing\Models\SupplierInvoiceLine;
use App\Modules\Purchasing\Models\SupplierProduct;
use App\Modules\Sales\Models\CashDrawerMovement;
use App\Modules\Sales\Models\Payment;
use App\Modules\Workforce\Models\Department;
use App\Modules\Workforce\Models\Employee;
use App\Modules\Workforce\Models\EmployeeBankAccount;
use App\Modules\Workforce\Models\EmployeeBpjsProfile;
use App\Modules\Workforce\Models\EmployeeTaxProfile;
use App\Modules\Workforce\Models\JobTitle;
use Tests\TestCase;

class MassAssignmentHardeningTest extends TestCase
{
    /**
     * @var array<int, class-string>
     */
    protected array $models71 = [
        // Customers (8)
        Customer::class,
        CustomerAddress::class,
        CustomerContact::class,
        CustomerGroup::class,
        CustomerLoyaltyAccount::class,
        CustomerMergeLog::class,
        LoyaltyLedger::class,
        LoyaltyTier::class,

        // Finance (35)
        AccountPeriodBalance::class,
        AccountingPeriod::class,
        ApLedgerEntry::class,
        ApPayment::class,
        ApPaymentAllocation::class,
        ArReceipt::class,
        BankReconciliationMatch::class,
        BankReconciliationSession::class,
        BankStatement::class,
        BankStatementLine::class,
        CashBankAccount::class,
        CashBankTransaction::class,
        ChartOfAccount::class,
        Expense::class,
        ExpenseApproval::class,
        ExpenseAttachment::class,
        ExpenseCategory::class,
        ExpenseLine::class,
        FinancialEventOutbox::class,
        FixedAsset::class,
        FixedAssetCategory::class,
        FixedAssetDepreciationSchedule::class,
        FixedAssetDisposal::class,
        InventoryValuationSnapshot::class,
        JournalEntry::class,
        JournalLine::class,
        PeriodClosingChecklist::class,
        PeriodClosingLog::class,
        PostingRule::class,
        PostingRuleLine::class,
        PostingRuleVersion::class,
        TaxAccountMapping::class,
        TaxCode::class,
        TaxLedgerEntry::class,
        TaxRate::class,

        // HR (6)
        Department::class,
        Employee::class,
        EmployeeBankAccount::class,
        EmployeeBpjsProfile::class,
        EmployeeTaxProfile::class,
        JobTitle::class,

        // Inventory (2)
        Ingredient::class,
        IngredientStockMovement::class,

        // Purchasing (18)
        AccountingPayableOutbox::class,
        GoodsReceivedNote::class,
        GoodsReceivedNoteLine::class,
        PurchaseApprovalLog::class,
        PurchaseApprovalRequest::class,
        PurchaseApprovalRule::class,
        PurchaseOrder::class,
        PurchaseOrderLine::class,
        PurchaseOrderLinePrAllocation::class,
        PurchaseOrderRevision::class,
        PurchaseRequest::class,
        PurchaseRequestLine::class,
        Supplier::class,
        SupplierContact::class,
        SupplierInvoice::class,
        SupplierInvoiceGrnMatch::class,
        SupplierInvoiceLine::class,
        SupplierProduct::class,

        // Sales (2)
        CashDrawerMovement::class,
        Payment::class,
    ];

    public function test_all_71_models_declare_explicit_non_empty_fillable_arrays(): void
    {
        $this->assertCount(71, $this->models71, 'Must check exactly 71 models');

        foreach ($this->models71 as $modelClass) {
            $model = new $modelClass;
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
        $customer = new Customer;
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
