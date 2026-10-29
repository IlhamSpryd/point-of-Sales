<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $accounting_period_id
 * @property string $gate_code
 * @property string $gate_name
 * @property string $status
 * @property string $discrepancy_amount
 * @property array|null $diagnostics_json
 * @property CarbonInterface|null $checked_at
 * @property int|null $checked_by_user_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class PeriodClosingChecklist extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'period_closing_checklists';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'diagnostics_json' => 'array',
            'checked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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

    public function checkedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }

    public function isPassed(): bool
    {
        return in_array($this->status, ['PASSED', 'WAIVED_BY_DIRECTOR'], true);
    }
}
