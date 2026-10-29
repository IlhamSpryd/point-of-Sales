<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $period_code
 * @property int $fiscal_year
 * @property int $period_number
 * @property CarbonInterface $start_date
 * @property CarbonInterface $end_date
 * @property AccountingPeriodStatusEnum $status
 * @property bool $is_adjustment_period
 * @property CarbonInterface|null $closed_at
 * @property int|null $closed_by_user_id
 * @property CarbonInterface|null $reopened_at
 * @property int|null $reopened_by_user_id
 * @property string|null $reopen_reason
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class AccountingPeriod extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'accounting_periods';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'period_number' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => AccountingPeriodStatusEnum::class,
            'is_adjustment_period' => 'boolean',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function reopenedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_user_id');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'accounting_period_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(AccountPeriodBalance::class, 'accounting_period_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(PeriodClosingChecklist::class, 'accounting_period_id');
    }

    public function closingLogs(): HasMany
    {
        return $this->hasMany(PeriodClosingLog::class, 'accounting_period_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isPostingAllowed(): bool
    {
        return $this->status->isPostingAllowed();
    }
}
