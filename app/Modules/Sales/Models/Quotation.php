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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model
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

    public function customerAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'customer_address_id');
    }

    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function parentQuotation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_quotation_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_quotation_id');
    }

    public function convertedSalesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'converted_sales_order_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class, 'quotation_id')->orderBy('line_number');
    }
}
