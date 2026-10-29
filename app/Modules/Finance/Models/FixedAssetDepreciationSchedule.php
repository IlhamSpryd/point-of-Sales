<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\AccountingPeriod;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Modules\Finance\Enums\DepreciationScheduleStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $fixed_asset_id
 * @property int $accounting_period_id
 * @property int $period_year
 * @property int $period_month
 * @property string $depreciation_amount
 * @property string $accumulated_depreciation_after
 * @property string $net_book_value_after
 * @property DepreciationScheduleStatusEnum $status
 * @property int|null $journal_entry_id
 * @property CarbonInterface|null $posted_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class FixedAssetDepreciationSchedule extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'fixed_asset_depreciation_schedules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'depreciation_amount' => 'string',
            'accumulated_depreciation_after' => 'string',
            'net_book_value_after' => 'string',
            'status' => DepreciationScheduleStatusEnum::class,
            'posted_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
