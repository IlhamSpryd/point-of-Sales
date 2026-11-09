<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\Finance\Enums\DepreciationMethodEnum;
use App\Modules\Finance\Enums\FixedAssetStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $category_id
 * @property string $asset_code
 * @property string $asset_name
 * @property CarbonInterface|string $acquisition_date
 * @property numeric-string $acquisition_cost
 * @property numeric-string $salvage_value
 * @property int $useful_life_months
 * @property DepreciationMethodEnum $depreciation_method
 * @property FixedAssetStatusEnum $status
 * @property numeric-string $accumulated_depreciation
 * @property numeric-string $net_book_value
 * @property int|null $acquisition_journal_id
 * @property int|null $supplier_id
 * @property string|null $location_note
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class FixedAsset extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'fixed_assets';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'category_id',
        'asset_code',
        'asset_name',
        'acquisition_date',
        'acquisition_cost',
        'salvage_value',
        'useful_life_months',
        'depreciation_method',
        'status',
        'accumulated_depreciation',
        'net_book_value',
        'acquisition_journal_id',
        'supplier_id',
        'location_note',
    ];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'acquisition_cost' => 'string',
            'salvage_value' => 'string',
            'useful_life_months' => 'integer',
            'depreciation_method' => DepreciationMethodEnum::class,
            'status' => FixedAssetStatusEnum::class,
            'accumulated_depreciation' => 'string',
            'net_book_value' => 'string',
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
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<FixedAssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FixedAssetCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function acquisitionJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'acquisition_journal_id');
    }

    /**
     * @return HasMany<FixedAssetDepreciationSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciationSchedule::class, 'fixed_asset_id');
    }

    /**
     * @return HasMany<FixedAssetDisposal, $this>
     */
    public function disposals(): HasMany
    {
        return $this->hasMany(FixedAssetDisposal::class, 'fixed_asset_id');
    }
}
