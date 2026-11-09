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
 * @property numeric-string $statement_ending_balance
 * @property numeric-string $book_ending_balance
 * @property numeric-string $unreconciled_difference
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

    protected $fillable = [
        'tenant_id',
        'cash_bank_account_id',
        'session_number',
        'statement_end_date',
        'statement_ending_balance',
        'book_ending_balance',
        'unreconciled_difference',
        'status',
        'started_by_user_id',
        'completed_by_user_id',
        'completed_at',
        'notes',
    ];

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

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    /**
     * @return HasMany<BankReconciliationMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(BankReconciliationMatch::class, 'reconciliation_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function startedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
