<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $policy_code
 * @property string $policy_name
 * @property bool $is_paid
 * @property float $default_days_per_year
 * @property bool $is_gender_specific
 * @property string|null $applicable_gender
 * @property bool $requires_attachment
 * @property string $expiration_policy
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant|null $tenant
 */
class LeavePolicy extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'leave_policies';

    protected $fillable = [
        'tenant_id',
        'policy_code',
        'policy_name',
        'is_paid',
        'default_days_per_year',
        'is_gender_specific',
        'applicable_gender',
        'requires_attachment',
        'expiration_policy',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'default_days_per_year' => 'float',
            'is_gender_specific' => 'boolean',
            'requires_attachment' => 'boolean',
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
     * @return HasMany<LeaveBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class, 'leave_policy_id');
    }

    /**
     * @return HasMany<LeaveRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'leave_policy_id');
    }

    /**
     * @return HasMany<LeaveTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(LeaveTransaction::class, 'leave_policy_id');
    }

    /**
     * @param  Builder<LeavePolicy>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
