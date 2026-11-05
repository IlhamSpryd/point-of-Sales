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
use App\Modules\Sales\Enums\QuotationStatusEnum;
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
 * @property numeric-string $total_amount
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $version_number
 * @property int|null $parent_quotation_id
 * @property int $customer_id
 * @property int|null $customer_address_id
 * @property int|null $sales_rep_user_id
 * @property int $payment_terms_days
 * @property int|null $approved_by_user_id
 * @property int|null $converted_sales_order_id
 * @property int $created_by_user_id
 * @property array<int|string, mixed>|string|null $customer_snapshot_json
 */
class Quotation extends Model
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
        'quotation_number',
        'version_number',
        'parent_quotation_id',
        'customer_id',
        'customer_address_id',
        'sales_rep_user_id',
        'status',
        'issue_date',
        'valid_until',
        'currency_code',
        'payment_terms_days',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'customer_snapshot_json',
        'notes',
        'terms_and_conditions',
        'requires_approval',
        'approved_by_user_id',
        'approved_at',
        'rejected_reason',
        'converted_sales_order_id',
        'converted_at',
        'idempotency_key',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatusEnum::class,
            'issue_date' => 'date',
            'valid_until' => 'date',
            'version_number' => 'integer',
            'payment_terms_days' => 'integer',
            'requires_approval' => 'boolean',
            'approved_at' => 'datetime',
            'converted_at' => 'datetime',
            'customer_snapshot_json' => 'array',
            'subtotal' => 'string',
            'discount_amount' => 'string',
            'tax_amount' => 'string',
            'total_amount' => 'string',
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
     * @return BelongsTo<CustomerAddress, $this>
     */
    public function customerAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'customer_address_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parentQuotation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_quotation_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_quotation_id');
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function convertedSalesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'converted_sales_order_id');
    }

    /**
     * @return HasMany<QuotationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class, 'quotation_id')->orderBy('line_number');
    }
}
