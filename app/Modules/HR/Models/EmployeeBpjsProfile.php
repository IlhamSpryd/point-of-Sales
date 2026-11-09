<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property string|null $bpjs_ketenagakerjaan_number
 * @property string|null $bpjs_kesehatan_number
 * @property int $jkk_risk_grade
 * @property bool $is_bpjs_tk_active
 * @property bool $is_bpjs_ks_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Employee $employee
 */
class EmployeeBpjsProfile extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'employee_bpjs_profiles';

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'bpjs_ketenagakerjaan_number',
        'bpjs_kesehatan_number',
        'jkk_risk_grade',
        'is_bpjs_tk_active',
        'is_bpjs_ks_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bpjs_ketenagakerjaan_number' => 'encrypted',
            'bpjs_kesehatan_number' => 'encrypted',
            'jkk_risk_grade' => 'integer',
            'is_bpjs_tk_active' => 'boolean',
            'is_bpjs_ks_active' => 'boolean',
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

    public function maskedBpjsKetenagakerjaan(): string
    {
        $num = $this->bpjs_ketenagakerjaan_number;
        if (empty($num)) {
            return '-';
        }

        $len = strlen($num);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).substr($num, -4);
    }

    public function maskedBpjsKesehatan(): string
    {
        $num = $this->bpjs_kesehatan_number;
        if (empty($num)) {
            return '-';
        }

        $len = strlen($num);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', $len - 4).substr($num, -4);
    }
}
