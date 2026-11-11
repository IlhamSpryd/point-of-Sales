<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $company_id
 * @property int $store_id
 * @property string $target_period
 * @property string $target_net_sales
 * @property string $target_gross_margin_pct
 * @property string $max_shrinkage_pct
 * @property string $max_labor_cost_pct
 */
class StoreTarget extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    protected $table = 'store_targets';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'store_id',
        'target_period',
        'target_net_sales',
        'target_gross_margin_pct',
        'max_shrinkage_pct',
        'max_labor_cost_pct',
    ];

    protected function casts(): array
    {
        return [
            'target_net_sales' => 'string',
            'target_gross_margin_pct' => 'string',
            'max_shrinkage_pct' => 'string',
            'max_labor_cost_pct' => 'string',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
