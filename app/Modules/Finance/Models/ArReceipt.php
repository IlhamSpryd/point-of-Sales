<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Models\ArPaymentAllocation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $store_id
 * @property int $customer_id
 * @property int|null $cash_bank_account_id
 * @property string $receipt_number
 * @property CarbonInterface $receipt_date
 * @property string $total_received_amount
 * @property string $total_allocated_amount
 * @property string $unapplied_credit_amount
 * @property string $bank_fee_amount
 * @property string $payment_method
 * @property string|null $payment_reference
 * @property string $status
 * @property int|null $journal_entry_id
 * @property int|null $cash_bank_transaction_id
 * @property string|null $idempotency_key
 * @property string|null $notes
 * @property int|null $created_by_user_id
 * @property int|null $posted_by_user_id
 * @property int|null $voided_by_user_id
 * @property string|null $void_reason
 * @property CarbonInterface|null $voided_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class ArReceipt extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'ar_receipts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'total_received_amount' => 'string',
            'total_allocated_amount' => 'string',
            'unapplied_credit_amount' => 'string',
            'bank_fee_amount' => 'string',
            'voided_at' => 'datetime',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function cashBankTransaction(): BelongsTo
    {
        return $this->belongsTo(CashBankTransaction::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ArPaymentAllocation::class, 'ar_receipt_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }
}
