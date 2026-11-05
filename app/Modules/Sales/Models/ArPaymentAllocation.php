<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $allocated_amount
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property int $customer_invoice_id
 * @property int|null $ar_receipt_id
 * @property int|null $cash_bank_account_id
 * @property int|null $journal_entry_id
 * @property int|null $payment_id
 * @property int $allocated_by_user_id
 * @property int|null $reversed_by_user_id
 */
class ArPaymentAllocation extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'customer_invoice_id',
        'payment_id',
        'payment_reference',
        'allocated_amount',
        'allocated_at',
        'allocated_by_user_id',
        'is_reversed',
        'reversed_at',
        'reversed_by_user_id',
        'reversal_reason',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:2',
            'allocated_at' => 'datetime',
            'is_reversed' => 'boolean',
            'reversed_at' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<CustomerInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }
}
