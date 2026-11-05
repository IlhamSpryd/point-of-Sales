<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\BankReconciliationMatchTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $reconciliation_session_id
 * @property int $bank_statement_line_id
 * @property int $cash_bank_transaction_id
 * @property string $match_type
 * @property numeric-string $matched_amount
 * @property int $matched_by_user_id
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read BankReconciliationSession|null $session
 */
class BankReconciliationMatch extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'bank_reconciliation_matches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'match_type' => BankReconciliationMatchTypeEnum::class,
            'matched_amount' => 'string',
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
     * @return BelongsTo<BankReconciliationSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(BankReconciliationSession::class, 'reconciliation_session_id');
    }

    /**
     * @return BelongsTo<BankStatementLine, $this>
     */
    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    /**
     * @return BelongsTo<CashBankTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CashBankTransaction::class, 'cash_bank_transaction_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function matchedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by_user_id');
    }
}
