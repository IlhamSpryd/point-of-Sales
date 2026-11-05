<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use App\Modules\HR\Enums\PtkpStatusEnum;
use App\Modules\HR\Enums\TaxCalculationMethodEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property string|null $tax_identification_number
 * @property PtkpStatusEnum $ptkp_status
 * @property TaxCalculationMethodEnum $calculation_method
 * @property bool $is_eligible_for_dtp
 * @property CarbonInterface $effective_from
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Employee $employee
 */
class EmployeeTaxProfile extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'employee_tax_profiles';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_identification_number' => 'encrypted',
            'ptkp_status' => PtkpStatusEnum::class,
            'calculation_method' => TaxCalculationMethodEnum::class,
            'is_eligible_for_dtp' => 'boolean',
            'effective_from' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Penyamaran NPWP untuk tampilan UI (UU PDP No. 27/2022).
     * Contoh: 09.***.***.*-012.000
     */
    public function maskedNpwp(): string
    {
        $npwp = $this->tax_identification_number;
        if (empty($npwp)) {
            return '-';
        }

        $digits = (string) preg_replace('/[^0-9]/', '', $npwp);
        $len = strlen($digits);

        if ($len < 8) {
            return str_repeat('*', $len);
        }

        // Format standar 15/16 digit
        return substr($digits, 0, 2).'.***.***.*-'.substr($digits, -6, 3).'.'.substr($digits, -3);
    }
}
