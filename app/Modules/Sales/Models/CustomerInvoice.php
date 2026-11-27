<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Traits\AssignsStore;
use App\Core\Traits\AssignsTenant;
use App\Core\Traits\BelongsToCompany;
use App\Core\Traits\ScopedToStore;
use App\Core\Traits\ScopedToTenant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Organization\Models\User;
use App\Modules\Sales\Contracts\InvoiceReaderInterface;
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
 * @property array<string, mixed>|null $customer_snapshot_json
 */
class CustomerInvoice extends Model implements InvoiceReaderInterface
{
    use AssignsStore;
    use AssignsTenant;
    use BelongsToCompany;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToStore;
    use ScopedToTenant;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
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
            'company_id' => 'integer',
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

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getTenantId(): int
    {
        return (int) $this->tenant_id;
    }

    public function getInvoiceNumber(): string
    {
        return (string) $this->invoice_number;
    }

    public function getTaxInvoiceSerialNumber(): ?string
    {
        return $this->tax_invoice_serial_number;
    }

    public function setTaxInvoiceSerialNumber(?string $serialNumber): void
    {
        $this->tax_invoice_serial_number = $serialNumber;
        $this->save();
    }

    public function getInvoiceDate(): mixed
    {
        return $this->invoice_date;
    }

    public function getSubtotal(): float|string
    {
        return $this->subtotal;
    }

    public function getTaxAmount(): float|string
    {
        return $this->tax_amount;
    }

    public function getTotalAmount(): float|string
    {
        return $this->total_amount;
    }

    public function getCustomerTaxId(): ?string
    {
        $snapshot = $this->customer_snapshot_json;

        return $this->customer->tax_id ?? (is_array($snapshot) && isset($snapshot['tax_id']) ? (string) $snapshot['tax_id'] : null);
    }

    public function getCustomerName(): ?string
    {
        $snapshot = $this->customer_snapshot_json;

        return $this->customer->name ?? (is_array($snapshot) && isset($snapshot['name']) ? (string) $snapshot['name'] : null);
    }

    public function getCustomerAddress(): ?string
    {
        $snapshot = $this->customer_snapshot_json;

        return is_array($snapshot) && isset($snapshot['address']) ? (string) $snapshot['address'] : null;
    }
}
