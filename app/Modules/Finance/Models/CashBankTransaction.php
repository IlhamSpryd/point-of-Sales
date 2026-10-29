<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\CashBankTransactionStatusEnum;
use App\Modules\Finance\Enums\CashBankTransactionTypeEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property int $cash_bank_account_id
 * @property int|null $transfer_pair_account_id
 * @property int|null $journal_entry_id
 * @property string $transaction_number
 * @property CashBankTransactionTypeEnum $transaction_type
 * @property CarbonInterface $transaction_date
 * @property string $amount
 * @property string $running_balance_after
 * @property string|null $description
 * @property string|null $reference_number
 * @property string $idempotency_key
 * @property CashBankTransactionStatusEnum $status
 * @property bool $is_reconciled
 * @property CarbonInterface|null $reconciled_at
 * @property int|null $created_by_user_id
 * @property int|null $posted_by_user_id
 * @property int|null $reversed_by_user_id
 * @property CarbonInterface|null $reversed_at
 * @property string|null $reversal_reason
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class CashBankTransaction extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'cash_bank_transactions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transaction_type' => CashBankTransactionTypeEnum::class,
            'status' => CashBankTransactionStatusEnum::class,
            'transaction_date' => 'date',
            'amount' => 'string',
            'running_balance_after' => 'string',
            'is_reconciled' => 'boolean',
            'reconciled_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function counterpartAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'transfer_pair_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function reversedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }
}
