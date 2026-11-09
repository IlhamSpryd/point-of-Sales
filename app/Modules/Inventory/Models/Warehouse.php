<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Core\Traits\AssignsTenant;
use App\Models\Company;
use App\Models\Scopes\TenantScope;
use App\Models\Store;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy(TenantScope::class)]
class Warehouse extends Model
{
    use AssignsTenant;
    use HasFactory;

    protected $table = 'warehouses';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'store_id',
        'warehouse_code',
        'warehouse_name',
        'warehouse_type',
        'address',
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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}

