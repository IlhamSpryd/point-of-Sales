<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $payroll_batch_id
 * @property int $employee_id
 * @property string $employee_number_snapshot
 * @property string $full_name_snapshot
 * @property string $department_name_snapshot
 * @property string $job_title_snapshot
 * @property int $primary_store_id
 * @property string $primary_store_name_snapshot
 * @property string $ptkp_status_snapshot
 * @property string $tax_calculation_method_snapshot
 * @property string $bank_name_snapshot
 * @property string $bank_account_number_snapshot
 * @property numeric-string $basic_salary_amount
 * @property numeric-string $fixed_allowances_amount
 * @property numeric-string $variable_allowances_amount
 * @property numeric-string $overtime_amount
 * @property numeric-string $commissions_amount
 * @property numeric-string $gross_earnings_amount
 * @property numeric-string $taxable_gross_amount
 * @property numeric-string $tax_pph21_amount
 * @property numeric-string $tax_pph21_dtp_amount
 * @property numeric-string $bpjs_ks_employer_amount
 * @property numeric-string $bpjs_ks_employee_amount
 * @property numeric-string $bpjs_tk_employer_amount
 * @property numeric-string $bpjs_tk_employee_amount
 * @property numeric-string $other_deductions_amount
 * @property numeric-string $net_pay_amount
 * @property string $payslip_token
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read PayrollBatch|null $batch
 * @property-read Employee|null $employee
 */
class PayrollRecord extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'payroll_records';

    protected $fillable = [
        'tenant_id',
        'payroll_batch_id',
        'employee_id',
        'employee_number_snapshot',
        'full_name_snapshot',
        'department_name_snapshot',
        'job_title_snapshot',
        'primary_store_id',
        'primary_store_name_snapshot',
        'ptkp_status_snapshot',
        'tax_calculation_method_snapshot',
        'bank_name_snapshot',
        'bank_account_number_snapshot',
        'basic_salary_amount',
        'fixed_allowances_amount',
        'variable_allowances_amount',
        'overtime_amount',
        'commissions_amount',
        'gross_earnings_amount',
        'taxable_gross_amount',
        'tax_pph21_amount',
        'tax_pph21_dtp_amount',
        'bpjs_ks_employer_amount',
        'bpjs_ks_employee_amount',
        'bpjs_tk_employer_amount',
        'bpjs_tk_employee_amount',
        'other_deductions_amount',
        'net_pay_amount',
        'payslip_token',
    ];

    protected function casts(): array
    {
        return [
            'primary_store_id' => 'integer',
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
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<PayrollBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayrollBatch::class, 'payroll_batch_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * @return HasMany<PayrollItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class, 'payroll_record_id');
    }
}
