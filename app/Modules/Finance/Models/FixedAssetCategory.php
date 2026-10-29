<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Modules\Finance\Enums\DepreciationMethodEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $category_code
 * @property string $category_name
 * @property int $asset_gl_account_id
 * @property int $accum_depr_gl_account_id
 * @property int $depr_expense_gl_account_id
 * @property int $useful_life_months
 * @property DepreciationMethodEnum $depreciation_method
 * @property bool $is_active
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class FixedAssetCategory extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'fixed_asset_categories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'useful_life_months' => 'integer',
            'depreciation_method' => DepreciationMethodEnum::class,
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assetGlAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'asset_gl_account_id');
    }

    public function accumDeprGlAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'accum_depr_gl_account_id');
    }

    public function deprExpenseGlAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'depr_expense_gl_account_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'category_id');
    }
}
