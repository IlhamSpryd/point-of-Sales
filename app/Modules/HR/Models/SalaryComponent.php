<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use App\Modules\HR\Enums\SalaryComponentTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $component_code
 * @property string $component_name
 * @property SalaryComponentTypeEnum $type
 * @property bool $is_taxable
 * @property bool $is_bpjs_kesehatan_subject
 * @property bool $is_bpjs_tk_subject
 * @property bool $is_fixed
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 */
class SalaryComponent extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'salary_components';

    protected $fillable = [
        'tenant_id',
        'component_code',
        'component_name',
        'type',
        'is_taxable',
        'is_bpjs_kesehatan_subject',
        'is_bpjs_tk_subject',
        'is_fixed',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => SalaryComponentTypeEnum::class,
            'is_taxable' => 'boolean',
            'is_bpjs_kesehatan_subject' => 'boolean',
            'is_bpjs_tk_subject' => 'boolean',
            'is_fixed' => 'boolean',
            'is_active' => 'boolean',
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
     * @return HasMany<EmployeeCompensation, $this>
     */
    public function compensations(): HasMany
    {
        return $this->hasMany(EmployeeCompensation::class, 'salary_component_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
