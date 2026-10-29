<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $account_code
 * @property string $account_name
 * @property AccountTypeEnum $account_type
 * @property AccountSubtypeEnum $account_subtype
 * @property NormalBalanceEnum $normal_balance
 * @property string|null $system_account_key
 * @property int|null $parent_id
 * @property int $level
 * @property bool $is_postable
 * @property bool $is_control_account
 * @property bool $allow_manual_journal
 * @property bool $is_reconcilable
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class ChartOfAccount extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'chart_of_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'account_type' => AccountTypeEnum::class,
            'account_subtype' => AccountSubtypeEnum::class,
            'normal_balance' => NormalBalanceEnum::class,
            'level' => 'integer',
            'is_postable' => 'boolean',
            'is_control_account' => 'boolean',
            'allow_manual_journal' => 'boolean',
            'is_reconcilable' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    public function periodBalances(): HasMany
    {
        return $this->hasMany(AccountPeriodBalance::class, 'account_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function hasTransactions(): bool
    {
        return $this->journalLines()->exists();
    }
}
