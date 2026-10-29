<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\ApPaymentStatusEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $supplier_id
 * @property int $cash_bank_account_id
 * @property int|null $journal_entry_id
 * @property string $payment_number
 * @property CarbonInterface $payment_date
 * @property string $payment_method
 * @property string $currency_code
 * @property string $exchange_rate
 * @property string $total_payment_amount
 * @property string $allocated_amount
 * @property string $unapplied_prepayment_amount
 * @property string $early_discount_amount
 * @property string $bank_fee_amount
 * @property string|null $reference_number
 * @property ApPaymentStatusEnum $status
 * @property string|null $notes
 * @property string $idempotency_key
 * @property int $created_by_user_id
 * @property int|null $approved_by_user_id
 * @property CarbonInterface|null $approved_at
 * @property int|null $posted_by_user_id
 * @property CarbonInterface|null $posted_at
 * @property int|null $voided_by_user_id
 * @property CarbonInterface|null $voided_at
 * @property string|null $void_reason
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class ApPayment extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'ap_payments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ApPaymentStatusEnum::class,
            'payment_date' => 'date',
            'exchange_rate' => 'string',
            'total_payment_amount' => 'string',
            'allocated_amount' => 'string',
            'unapplied_prepayment_amount' => 'string',
            'early_discount_amount' => 'string',
            'bank_fee_amount' => 'string',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'cash_bank_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ApPaymentAllocation::class, 'ap_payment_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function isDraft(): bool
    {
        return $this->status === ApPaymentStatusEnum::DRAFT;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === ApPaymentStatusEnum::PENDING_APPROVAL;
    }

    public function isPosted(): bool
    {
        return $this->status === ApPaymentStatusEnum::POSTED;
    }

    public function isVoided(): bool
    {
        return $this->status === ApPaymentStatusEnum::VOIDED;
    }
}
