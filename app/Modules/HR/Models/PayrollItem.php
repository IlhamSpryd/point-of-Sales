<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use App\Modules\HR\Enums\SalaryComponentTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $payroll_record_id
 * @property string $component_code
 * @property string $component_name
 * @property SalaryComponentTypeEnum $type
 * @property float $amount
 * @property bool $is_taxable
 * @property int|null $statutory_rule_version_id
 * @property string|null $notes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read PayrollRecord|null $payrollRecord
 * @property-read StatutoryRuleVersion|null $statutoryRuleVersion
 */
class PayrollItem extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'payroll_items';

    protected $fillable = [
        'tenant_id',
        'payroll_record_id',
        'component_code',
        'component_name',
        'type',
        'amount',
        'is_taxable',
        'statutory_rule_version_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => SalaryComponentTypeEnum::class,
            'amount' => 'float',
            'is_taxable' => 'boolean',
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
     * @return BelongsTo<PayrollRecord, $this>
     */
    public function payrollRecord(): BelongsTo
    {
        return $this->belongsTo(PayrollRecord::class, 'payroll_record_id');
    }

    /**
     * @return BelongsTo<StatutoryRuleVersion, $this>
     */
    public function statutoryRuleVersion(): BelongsTo
    {
        return $this->belongsTo(StatutoryRuleVersion::class, 'statutory_rule_version_id');
    }
}
