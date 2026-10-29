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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerInvoice extends Model
{
    use AssignsStore;
    use AssignsTenant;
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

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerInvoiceLine::class, 'customer_invoice_id')->orderBy('line_number');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(ArLedgerEntry::class, 'customer_invoice_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ArPaymentAllocation::class, 'customer_invoice_id');
    }

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
