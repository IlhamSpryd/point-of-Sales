<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderReturnItem;
use App\Models\PaymentRefund;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property numeric-string $subtotal_returned
 * @property numeric-string $discount_reversed
 * @property numeric-string $tax_returned
 * @property numeric-string $service_charge_returned
 * @property numeric-string $total_refundable_amount
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $order_id
 * @property int|null $customer_id
 * @property int|null $customer_invoice_id
 * @property int $shift_id
 * @property int $device_id
 * @property int $requested_by_user_id
 * @property int $approved_by_user_id
 */
class OrderReturn extends Model
{
    use AssignsStore, AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_FULL = 'full';

    public const TYPE_PARTIAL = 'partial';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'order_id',
        'customer_id',
        'customer_invoice_id',
        'shift_id',
        'device_id',
        'return_number',
        'idempotency_key',
        'status',
        'return_type',
        'subtotal_returned',
        'discount_reversed',
        'tax_returned',
        'service_charge_returned',
        'total_refundable_amount',
        'reason',
        'requested_by_user_id',
        'approved_by_user_id',
        'completed_at',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * @return BelongsTo<CustomerInvoice, $this>
     */
    public function customerInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id');
    }

    /**
     * @return HasOne<SalesCreditNote, $this>
     */
    public function creditNote(): HasOne
    {
        return $this->hasOne(SalesCreditNote::class, 'order_return_id');
    }

    protected function casts(): array
    {
        return [
            'subtotal_returned' => 'decimal:2',
            'discount_reversed' => 'decimal:2',
            'tax_returned' => 'decimal:2',
            'service_charge_returned' => 'decimal:2',
            'total_refundable_amount' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return HasMany<OrderReturnItem, $this>
     */
    public function returnItems(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class);
    }

    /**
     * @return HasMany<PaymentRefund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id')->withTrashed();
    }
}
