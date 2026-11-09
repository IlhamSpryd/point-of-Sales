<?php

declare(strict_types=1);

namespace Tests\Unit\Casts;

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

class DecimalPrecisionCastsTest extends TestCase
{
    public function test_all_16_models_declare_exact_decimal_casts(): void
    {
        $expectedCasts = [
            CommissionRule::class => [
                'rate_percent' => 'decimal:4',
                'rate_amount' => 'decimal:2',
                'min_sales_threshold' => 'decimal:2',
            ],
            LeaveTransaction::class => [
                'days_delta' => 'decimal:2',
            ],
            LeaveRequest::class => [
                'total_days' => 'decimal:2',
            ],
            AttendanceRecord::class => [
                'latitude' => 'decimal:7',
                'longitude' => 'decimal:7',
            ],
            EmployeeCompensation::class => [
                'amount' => 'decimal:2',
            ],
            OvertimeRecord::class => [
                'payable_hours' => 'decimal:2',
                'hourly_rate_basis' => 'decimal:2',
                'calculated_amount' => 'decimal:2',
            ],
            LeavePolicy::class => [
                'default_days_per_year' => 'decimal:2',
            ],
            PayrollAdjustment::class => [
                'amount' => 'decimal:2',
            ],
            PayrollRecord::class => [
                'basic_salary_amount' => 'decimal:2',
                'fixed_allowances_amount' => 'decimal:2',
                'variable_allowances_amount' => 'decimal:2',
                'overtime_amount' => 'decimal:2',
                'commissions_amount' => 'decimal:2',
                'gross_earnings_amount' => 'decimal:2',
                'taxable_gross_amount' => 'decimal:2',
                'tax_pph21_amount' => 'decimal:2',
                'tax_pph21_dtp_amount' => 'decimal:2',
                'bpjs_ks_employer_amount' => 'decimal:2',
                'bpjs_ks_employee_amount' => 'decimal:2',
                'bpjs_tk_employer_amount' => 'decimal:2',
                'bpjs_tk_employee_amount' => 'decimal:2',
                'other_deductions_amount' => 'decimal:2',
                'net_pay_amount' => 'decimal:2',
            ],
            CommissionRecord::class => [
                'calculation_basis_amount' => 'decimal:2',
                'commission_amount' => 'decimal:2',
            ],
            LeaveBalance::class => [
                'allocated_days' => 'decimal:2',
                'used_days' => 'decimal:2',
                'remaining_days' => 'decimal:2',
                'expired_days' => 'decimal:2',
            ],
            PayrollBatch::class => [
                'total_gross_amount' => 'decimal:2',
                'total_tax_amount' => 'decimal:2',
                'total_bpjs_employer' => 'decimal:2',
                'total_bpjs_employee' => 'decimal:2',
                'total_other_deductions' => 'decimal:2',
                'total_net_amount' => 'decimal:2',
            ],
            PayrollItem::class => [
                'amount' => 'decimal:2',
            ],
            PriceListItem::class => [
                'min_quantity' => 'decimal:4',
                'max_quantity' => 'decimal:4',
                'price' => 'decimal:2',
                'discount_percentage' => 'decimal:2',
            ],
            ProductVariant::class => [
                'price_override' => 'decimal:2',
                'standard_cost' => 'decimal:2',
            ],
            ProductBundleItem::class => [
                'quantity' => 'decimal:4',
                'cost_allocation_ratio' => 'decimal:4',
            ],
        ];

        $totalChecked = 0;
        foreach ($expectedCasts as $modelClass => $attributes) {
            $model = new $modelClass();
            $casts = $model->getCasts();

            foreach ($attributes as $attr => $expectedCast) {
                $this->assertArrayHasKey(
                    $attr,
                    $casts,
                    "Model {$modelClass} is missing cast definition for {$attr}"
                );
                $this->assertEquals(
                    $expectedCast,
                    $casts[$attr],
                    "Model {$modelClass} attribute {$attr} cast mismatch. Expected: {$expectedCast}, got: {$casts[$attr]}"
                );
                $totalChecked++;
            }
        }

        $this->assertEquals(49, $totalChecked, 'Must check exactly 49 converted decimal cast attributes');
    }

    public function test_decimal_casts_hydrate_values_as_formatted_string_decimals(): void
    {
        // PayrollRecord sample
        $payroll = new PayrollRecord([
            'basic_salary_amount' => 5000000.5,
            'net_pay_amount' => 4500123.75,
        ]);
        $this->assertSame('5000000.50', $payroll->basic_salary_amount);
        $this->assertSame('4500123.75', $payroll->net_pay_amount);

        // AttendanceRecord sample (GPS coordinates)
        $attendance = new AttendanceRecord([
            'latitude' => -6.2087634,
            'longitude' => 106.8455990,
        ]);
        $this->assertSame('-6.2087634', $attendance->latitude);
        $this->assertSame('106.8455990', $attendance->longitude);

        // PriceListItem sample (quantity with 4 decimal places)
        $priceItem = new PriceListItem([
            'min_quantity' => 12.5,
            'price' => 15000,
        ]);
        $this->assertSame('12.5000', $priceItem->min_quantity);
        $this->assertSame('15000.00', $priceItem->price);
    }
}
