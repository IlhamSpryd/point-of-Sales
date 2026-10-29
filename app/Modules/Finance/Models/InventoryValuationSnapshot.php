<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\AccountingPeriod;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $accounting_period_id
 * @property int $store_id
 * @property CarbonInterface|string $snapshot_date
 * @property string $item_type
 * @property int $item_id
 * @property string $closing_qty
 * @property string $average_unit_cost
 * @property string $total_valuation_amount
 * @property int|null $gl_account_id
 * @property string $gl_balance_amount
 * @property string $variance_amount
 * @property string $reconciliation_status
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class InventoryValuationSnapshot extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'inventory_valuation_snapshots';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'closing_qty' => 'string',
            'average_unit_cost' => 'string',
            'total_valuation_amount' => 'string',
            'gl_balance_amount' => 'string',
            'variance_amount' => 'string',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_account_id');
    }
}
