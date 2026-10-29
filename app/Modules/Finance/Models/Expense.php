<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\ExpenseStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property int $category_id
 * @property string $expense_number
 * @property CarbonInterface|string $expense_date
 * @property string $subtotal_amount
 * @property string $tax_amount
 * @property string $total_amount
 * @property string|null $description
 * @property ExpenseStatusEnum $status
 * @property int|null $submitted_by_user_id
 * @property CarbonInterface|null $submitted_at
 * @property int|null $approved_by_user_id
 * @property CarbonInterface|null $approved_at
 * @property string|null $rejection_reason
 * @property int|null $cash_bank_account_id
 * @property int|null $cash_bank_transaction_id
 * @property int|null $accrual_journal_id
 * @property int|null $payment_journal_id
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Expense extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'expenses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'subtotal_amount' => 'string',
            'tax_amount' => 'string',
            'total_amount' => 'string',
            'status' => ExpenseStatusEnum::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function cashBankTransaction(): BelongsTo
    {
        return $this->belongsTo(CashBankTransaction::class, 'cash_bank_transaction_id');
    }

    public function accrualJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'accrual_journal_id');
    }

    public function paymentJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payment_journal_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class, 'expense_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class, 'expense_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ExpenseApproval::class, 'expense_id');
    }
}
