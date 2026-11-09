<?php

declare(strict_types=1);

namespace Tests\Unit\Adversarial;

use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\CommissionRecord;
use App\Modules\HR\Models\CommissionRule;
use App\Modules\HR\Models\EmployeeCompensation;
use App\Modules\HR\Models\LeaveBalance;
use App\Modules\HR\Models\LeavePolicy;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveTransaction;
use App\Modules\HR\Models\OvertimeRecord;
use App\Modules\HR\Models\PayrollAdjustment;
use App\Modules\HR\Models\PayrollBatch;
use App\Modules\HR\Models\PayrollItem;
use App\Modules\HR\Models\PayrollRecord;
use App\Modules\Products\Models\PriceListItem;
use App\Modules\Products\Models\ProductBundleItem;
use App\Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class Milestone1AdversarialUnitTest extends TestCase
{
    /**
     * Complete inventory of all 71 models remediated under DEF-04.
     *
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

    /**
     * DEF-04 Empirical Challenge:
     * Stress-test that hostile properties (id, is_admin, fake_injected_column, created_at, deleted_at)
     * CANNOT be mass-assigned to ANY of the 71 converted models.
     */
    public function test_all_71_models_reject_hostile_mass_assignment_injections(): void
    {
        $this->assertCount(71, $this->models71, 'Must empirically test all 71 converted models');

        $hostilePayload = [
            'id' => 999999,
            'is_admin' => 1,
            'is_super_admin' => true,
            'fake_injected_column' => 'adversarial_payload_injection',
            'created_at' => '2000-01-01 00:00:00',
            'deleted_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
            'permissions' => ['*'],
        ];

        foreach ($this->models71 as $modelClass) {
            /** @var \Illuminate\Database\Eloquent\Model $instance */
            $instance = new $modelClass();
            $fillable = $instance->getFillable();
            $guarded = $instance->getGuarded();

            // 1. Guarded must not be just ['id']
            $this->assertNotEquals(
                ['id'],
                $guarded,
                "Model {$modelClass} must not declare protected \$guarded = ['id']."
            );

            // 2. Explicit fillable array must exist and be non-empty
            $this->assertNotEmpty(
                $fillable,
                "Model {$modelClass} must have an explicit non-empty \$fillable array."
            );

            // 3. Prohibited keys must NOT be declared in $fillable
            $prohibitedKeys = ['id', 'created_at', 'updated_at', 'deleted_at', 'is_admin', 'is_super_admin', 'fake_injected_column'];
            foreach ($prohibitedKeys as $prohibited) {
                $this->assertNotContains(
                    $prohibited,
                    $fillable,
                    "Model {$modelClass} MUST NOT include '{$prohibited}' in \$fillable."
                );
                $this->assertFalse(
                    $instance->isFillable($prohibited),
                    "Model {$modelClass} must report isFillable('{$prohibited}') as false."
                );
            }

            // 4. Empirical mass assignment attempt
            $instance->fill($hostilePayload);

            // 5. Verify hostile payload was discarded
            $this->assertNull($instance->id, "Model {$modelClass} accepted injected 'id' via mass assignment!");
            $this->assertFalse(isset($instance->is_admin), "Model {$modelClass} accepted injected 'is_admin' via mass assignment!");
            $this->assertFalse(isset($instance->is_super_admin), "Model {$modelClass} accepted injected 'is_super_admin' via mass assignment!");
            $this->assertFalse(isset($instance->fake_injected_column), "Model {$modelClass} accepted injected 'fake_injected_column' via mass assignment!");
            $this->assertFalse(isset($instance->created_at), "Model {$modelClass} accepted injected 'created_at' via mass assignment!");
            $this->assertFalse(isset($instance->deleted_at), "Model {$modelClass} accepted injected 'deleted_at' via mass assignment!");
        }
    }

    /**
     * DEF-03 Empirical Challenge:
     * Stress-test precision with extreme edge cases (0.0001, 9999999999999.99, IEEE 754 precision drift,
     * decimal strings, negative values, nulls, GPS 7-decimal coordinates) across HR and Product models.
     */
    public function test_decimal_casts_precision_under_extreme_edge_cases(): void
    {
        // 1. Extreme small values (0.0001) on 4-decimal attributes
        $priceItem = new PriceListItem([
            'min_quantity' => 0.0001,
            'max_quantity' => '0.0001',
            'price' => '15000.00',
            'discount_percentage' => '5.50',
        ]);
        $this->assertSame('0.0001', $priceItem->min_quantity, '0.0001 float must cast to exact string decimal:4');
        $this->assertSame('0.0001', $priceItem->max_quantity, '0.0001 string must cast to exact string decimal:4');

        $bundleItem = new ProductBundleItem([
            'quantity' => 0.0001,
            'cost_allocation_ratio' => 0.0005,
        ]);
        $this->assertSame('0.0001', $bundleItem->quantity);
        $this->assertSame('0.0005', $bundleItem->cost_allocation_ratio);

        $commRule = new CommissionRule([
            'rate_percent' => 0.0001,
            'rate_amount' => 1000.00,
            'min_sales_threshold' => 50000.00,
        ]);
        $this->assertSame('0.0001', $commRule->rate_percent);

        // 2. Sub-decimal rounding behavior (5th decimal place rounded to 4th)
        $priceItemSub1 = new PriceListItem(['min_quantity' => 0.00004]);
        $this->assertSame('0.0000', $priceItemSub1->min_quantity, '0.00004 must round down to 0.0000');

        $priceItemSub2 = new PriceListItem(['min_quantity' => 0.00006]);
        $this->assertSame('0.0001', $priceItemSub2->min_quantity, '0.00006 must round up to 0.0001');

        // 3. Large monetary boundary (9,999,999,999,999.99 - near max decimal(15,2))
        $largeMonetaryString = '9999999999999.99';
        $payrollLarge = new PayrollRecord([
            'basic_salary_amount' => $largeMonetaryString,
            'gross_earnings_amount' => $largeMonetaryString,
            'net_pay_amount' => $largeMonetaryString,
        ]);
        $this->assertSame($largeMonetaryString, $payrollLarge->basic_salary_amount, 'Large 15-digit decimal string must not truncate or drift');
        $this->assertSame($largeMonetaryString, $payrollLarge->gross_earnings_amount);
        $this->assertSame($largeMonetaryString, $payrollLarge->net_pay_amount);

        // 4. IEEE 754 precision drift elimination
        // Classic IEEE 754: 0.1 + 0.2 has float representation 0.30000000000000004...
        $floatSum = 0.1 + 0.2;
        $this->assertSame('0.30000000000000004', sprintf('%.17f', $floatSum));
        $payrollDrift = new PayrollRecord([
            'basic_salary_amount' => $floatSum,
        ]);
        $this->assertSame('0.30', $payrollDrift->basic_salary_amount, 'decimal:2 cast must eliminate IEEE 754 float drift and format exact string');

        // Another IEEE 754 test: float multiplication
        $floatMult = (0.1 + 0.7) * 10;
        $productVariant = new ProductVariant([
            'price_override' => $floatMult,
            'standard_cost' => $floatMult,
        ]);
        $this->assertSame('8.00', $productVariant->price_override, 'decimal:2 cast must produce exact decimal string');
        $this->assertSame('8.00', $productVariant->standard_cost);

        // 5. Negative monetary amounts
        $payrollNegative = new PayrollRecord([
            'other_deductions_amount' => -150000.75,
            'tax_pph21_amount' => '-25000.50',
        ]);
        $this->assertSame('-150000.75', $payrollNegative->other_deductions_amount);
        $this->assertSame('-25000.50', $payrollNegative->tax_pph21_amount);

        // 6. GPS Coordinate precision (decimal:7) in AttendanceRecord
        $attendance = new AttendanceRecord([
            'latitude' => -6.2087634,
            'longitude' => 106.8455990,
        ]);
        $this->assertSame('-6.2087634', $attendance->latitude, 'Negative 7-decimal latitude must be exact');
        $this->assertSame('106.8455990', $attendance->longitude, 'Positive 7-decimal longitude must retain trailing zero');

        // 7. Null preservation (must remain null, not cast to "0.00")
        $payrollNull = new PayrollRecord([
            'overtime_amount' => null,
            'commissions_amount' => null,
        ]);
        $this->assertNull($payrollNull->overtime_amount, 'Null attribute must remain null');
        $this->assertNull($payrollNull->commissions_amount, 'Null attribute must remain null');

        // 8. Model array and JSON serialization
        $serialized = $payrollLarge->toArray();
        $this->assertIsString($serialized['basic_salary_amount']);
        $this->assertSame($largeMonetaryString, $serialized['basic_salary_amount']);

        $json = json_encode($serialized);
        $this->assertStringContainsString('"basic_salary_amount":"9999999999999.99"', $json);
    }
}
