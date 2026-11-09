<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Core\Traits\AssignsTenant;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
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
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
     * @return HasMany<Warehouse, $this>
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(\App\Modules\Inventory\Models\Warehouse::class);
    }
}

