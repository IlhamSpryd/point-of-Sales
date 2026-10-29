<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Sales\Enums\SalesOrderFulfillmentStatusEnum;
use App\Modules\Sales\Enums\SalesOrderInvoicingStatusEnum;
use App\Modules\Sales\Enums\SalesOrderStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
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
        'so_number',
        'customer_id',
        'quotation_id',
        'customer_po_number',
        'billing_address_id',
        'shipping_address_id',
        'order_source',
        'channel_code',
        'external_order_id',
        'status',
        'fulfillment_status',
        'invoicing_status',
        'order_date',
        'expected_delivery_date',
        'payment_terms_days',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'shipping_amount',
        'total_amount',
        'reserved_credit_amount',
        'customer_snapshot_json',
        'source_repeat_order_id',
        'credit_override_approved_by',
        'credit_override_reason',
        'idempotency_key',
        'created_by_user_id',
        'confirmed_by_user_id',
        'confirmed_at',
        'cancelled_by_user_id',
        'cancelled_at',
        'cancellation_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatusEnum::class,
            'fulfillment_status' => SalesOrderFulfillmentStatusEnum::class,
            'invoicing_status' => SalesOrderInvoicingStatusEnum::class,
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'payment_terms_days' => 'integer',
            'customer_snapshot_json' => 'array',
            'subtotal' => 'string',
            'discount_amount' => 'string',
            'tax_amount' => 'string',
            'shipping_amount' => 'string',
            'total_amount' => 'string',
            'reserved_credit_amount' => 'string',
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

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function billingAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'billing_address_id');
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'shipping_address_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function creditOverrideApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credit_override_approved_by');
    }

    public function sourceRepeatOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_repeat_order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class, 'sales_order_id')->orderBy('line_number');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'sales_order_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class, 'sales_order_id');
    }
}
