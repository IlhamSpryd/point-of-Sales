<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Enums\CustomerInvoiceStatusEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property numeric-string $subtotal
 * @property numeric-string $discount_amount
 * @property numeric-string $tax_amount
 * @property numeric-string $shipping_amount
 * @property numeric-string $total_amount
 * @property numeric-string $paid_amount
 * @property numeric-string $credit_applied_amount
 * @property numeric-string $balance_due
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $customer_id
 * @property int|null $sales_order_id
 * @property int|null $order_id
 * @property int|null $gl_journal_entry_id
 * @property int $payment_terms_days
 * @property int|null $posted_by_user_id
 * @property int|null $voided_by_user_id
 */
class CustomerInvoice extends Model
{
    use AssignsStore;
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToStore;
    use ScopedToTenant;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'invoice_number',
        'customer_id',
        'sales_order_id',
        'order_id',
        'customer_po_number',
        'status',
        'invoice_date',
        'due_date',
        'payment_terms_days',
        'currency_code',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'tax_invoice_serial_number',
        'tax_invoice_status',
        'shipping_amount',
        'total_amount',
        'paid_amount',
        'credit_applied_amount',
        'balance_due',
        'customer_snapshot_json',
        'notes',
        'terms_and_conditions',
        'idempotency_key',
        'posted_by_user_id',
        'posted_at',
        'voided_by_user_id',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerInvoiceStatusEnum::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'payment_terms_days' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'credit_applied_amount' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'customer_snapshot_json' => 'array',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
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
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    /**
     * @return HasMany<CustomerInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CustomerInvoiceLine::class, 'customer_invoice_id')->orderBy('line_number');
    }

    /**
     * @return HasMany<ArLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(ArLedgerEntry::class, 'customer_invoice_id');
    }

    /**
     * @return HasMany<ArPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ArPaymentAllocation::class, 'customer_invoice_id');
    }

    /**
     * @return HasMany<SalesCreditNoteApplication, $this>
     */
    public function creditNoteApplications(): HasMany
    {
        return $this->hasMany(SalesCreditNoteApplication::class, 'customer_invoice_id');
    }

    public function isOverdue(): bool
    {
        if (in_array($this->status, [CustomerInvoiceStatusEnum::PAID, CustomerInvoiceStatusEnum::VOIDED, CustomerInvoiceStatusEnum::DRAFT], true)) {
            return false;
        }

        if (bccomp((string) $this->balance_due, '0.00', 2) <= 0) {
            return false;
        }

        return Carbon::parse($this->due_date)->isPast();
    }

    public function daysOverdue(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return (int) Carbon::parse($this->due_date)->diffInDays(Carbon::today(), false);
    }

    public function canBeVoided(): bool
    {
        return $this->status === CustomerInvoiceStatusEnum::POSTED
            && bccomp((string) $this->paid_amount, '0.00', 2) === 0
            && bccomp((string) $this->credit_applied_amount, '0.00', 2) === 0;
    }
}
