<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Sales\Enums\CreditNoteStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property numeric-string $subtotal
 * @property numeric-string $tax_amount
 * @property numeric-string $total_amount
 * @property numeric-string $applied_amount
 * @property numeric-string $remaining_amount
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $customer_id
 * @property int|null $order_return_id
 * @property int|null $origin_customer_invoice_id
 * @property int|null $origin_order_id
 * @property int|null $issued_by_user_id
 */
class SalesCreditNote extends Model
{
    use AssignsStore, AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;
    use SoftDeletes;

    protected $table = 'sales_credit_notes';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'credit_note_number',
        'customer_id',
        'order_return_id',
        'origin_customer_invoice_id',
        'origin_order_id',
        'status',
        'issue_date',
        'reason_code',
        'subtotal',
        'tax_amount',
        'total_amount',
        'applied_amount',
        'remaining_amount',
        'notes',
        'idempotency_key',
        'issued_by_user_id',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CreditNoteStatusEnum::class,
            'issue_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'applied_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<OrderReturn, $this>
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'order_return_id');
    }

    /**
     * @return BelongsTo<CustomerInvoice, $this>
     */
    public function originCustomerInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'origin_customer_invoice_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function originOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'origin_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id')->withTrashed();
    }

    /**
     * @return HasMany<SalesCreditNoteApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(SalesCreditNoteApplication::class, 'sales_credit_note_id');
    }
}
