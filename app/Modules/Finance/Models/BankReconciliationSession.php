<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\BankReconciliationSessionStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $cash_bank_account_id
 * @property string $session_number
 * @property CarbonInterface $statement_end_date
 * @property string $statement_ending_balance
 * @property string $book_ending_balance
 * @property string $unreconciled_difference
 * @property BankReconciliationSessionStatusEnum $status
 * @property int $started_by_user_id
 * @property int|null $completed_by_user_id
 * @property CarbonInterface|null $completed_at
 * @property string|null $notes
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class BankReconciliationSession extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'bank_reconciliation_sessions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'statement_end_date' => 'date',
            'statement_ending_balance' => 'string',
            'book_ending_balance' => 'string',
            'unreconciled_difference' => 'string',
            'status' => BankReconciliationSessionStatusEnum::class,
            'completed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankReconciliationMatch::class, 'reconciliation_session_id');
    }

    public function startedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
