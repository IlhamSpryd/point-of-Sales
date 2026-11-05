<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

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
 * @property int $account_id
 * @property int|null $store_id
 * @property int $store_scope_key
 * @property numeric-string $opening_debit
 * @property numeric-string $opening_credit
 * @property numeric-string $period_debit
 * @property numeric-string $period_credit
 * @property numeric-string $closing_debit
 * @property numeric-string $closing_credit
 * @property numeric-string $net_movement
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class AccountPeriodBalance extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'account_period_balances';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'store_scope_key' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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
     * @return BelongsTo<AccountingPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
