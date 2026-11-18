<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Core\Scopes\TenantScope;
use App\Core\Traits\AssignsTenant;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy(TenantScope::class)]
class Company extends Model
{
    use AssignsTenant;
    use HasFactory;

    protected $table = 'companies';

    protected $fillable = [
        'tenant_id',
        'company_code',
        'company_name',
        'legal_name',
        'npwp',
        'tax_address',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Get the canonical default company for the given tenant.
     */
    public static function getDefaultCompanyForTenant(int $tenantId): ?self
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->first();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<Warehouse, $this>
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }
}
