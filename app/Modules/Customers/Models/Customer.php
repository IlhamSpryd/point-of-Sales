<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\ScopedToTenant;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $customer_group_id
 * @property int|null $default_store_id
 * @property int|null $sales_rep_user_id
 * @property int|null $price_list_id
 * @property int|null $merged_into_customer_id
 * @property int|null $phone_uniqueness_key
 * @property int $tenant_id
 * @property string $name
 * @property string|null $company_name
 * @property string|null $tax_id
 */
class Customer extends Model
{
    use AssignsTenant;
    use ScopedToTenant;
    use SoftDeletes;

    protected $fillable = [
        'customer_code',
        'name',
        'customer_type',
        'customer_group_id',
        'company_name',
        'tax_id',
        'business_registration_no',
        'default_store_id',
        'sales_rep_user_id',
        'price_list_id',
        'phone',
        'email',
        'date_of_birth',
        'join_date',
        'is_active',
        'is_loyalty_eligible',
        'merged_into_customer_id',
        'phone_uniqueness_key',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_loyalty_eligible' => 'boolean',
            'date_of_birth' => 'date',
            'join_date' => 'date',
        ];
    }

    /**
     * @return HasOne<\App\Modules\Pricing\Models\CustomerLoyaltyAccount, $this>
     */
    public function loyaltyAccount(): HasOne
    {
        return $this->hasOne(\App\Modules\Pricing\Models\CustomerLoyaltyAccount::class);
    }

    /**
     * @return HasMany<\App\Modules\Sales\Models\Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(\App\Modules\Sales\Models\Order::class);
    }

    /**
     * @return HasMany<\App\Modules\Pricing\Models\LoyaltyLedger, $this>
     */
    public function loyaltyLedgers(): HasMany
    {
        return $this->hasMany(\App\Modules\Pricing\Models\LoyaltyLedger::class);
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /**
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /**
     * @return HasMany<CustomerContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    /**
     * @return HasOne<CustomerAddress, $this>
     */
    public function defaultBillingAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('is_default_billing', true);
    }

    /**
     * @return HasOne<CustomerAddress, $this>
     */
    public function defaultShippingAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('is_default_shipping', true);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function defaultStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'default_store_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_user_id');
    }

    /**
     * @return BelongsTo<\App\Modules\Pricing\Models\PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Pricing\Models\PriceList::class, 'price_list_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function mergedIntoCustomer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_customer_id');
    }

    /**
     * @return HasMany<CustomerMergeLog, $this>
     */
    public function mergeLogsAsSource(): HasMany
    {
        return $this->hasMany(CustomerMergeLog::class, 'source_customer_id');
    }

    /**
     * @return HasMany<CustomerMergeLog, $this>
     */
    public function mergeLogsAsTarget(): HasMany
    {
        return $this->hasMany(CustomerMergeLog::class, 'target_customer_id');
    }

    /**
     * @return HasOne<CustomerCreditAccount, $this>
     */
    public function creditAccount(): HasOne
    {
        return $this->hasOne(CustomerCreditAccount::class);
    }

    /**
     * @return HasMany<CustomerCreditLog, $this>
     */
    public function creditLogs(): HasMany
    {
        return $this->hasMany(CustomerCreditLog::class);
    }

    /**
     * @return HasMany<\App\Modules\Sales\Models\CustomerInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(\App\Modules\Sales\Models\CustomerInvoice::class);
    }

    /**
     * @return HasMany<\App\Modules\Sales\Models\ArLedgerEntry, $this>
     */
    public function arLedgerEntries(): HasMany
    {
        return $this->hasMany(\App\Modules\Sales\Models\ArLedgerEntry::class);
    }

    /**
     * @return HasMany<\App\Modules\Sales\Models\ArPaymentAllocation, $this>
     */
    public function arPaymentAllocations(): HasMany
    {
        return $this->hasMany(\App\Modules\Sales\Models\ArPaymentAllocation::class);
    }

    /**
     * @return HasMany<\App\Modules\Pricing\Models\Voucher, $this>
     */
    public function vouchers(): HasMany
    {
        return $this->hasMany(\App\Modules\Pricing\Models\Voucher::class);
    }

    /**
     * @return HasMany<\App\Modules\Pricing\Models\GiftCard, $this>
     */
    public function giftCards(): HasMany
    {
        return $this->hasMany(\App\Modules\Pricing\Models\GiftCard::class);
    }

    /**
     * @return HasOne<\App\Modules\Pricing\Models\StoreCreditAccount, $this>
     */
    public function storeCreditAccount(): HasOne
    {
        return $this->hasOne(\App\Modules\Pricing\Models\StoreCreditAccount::class);
    }

    /**
     * @return HasMany<\App\Modules\Pricing\Models\StoreCreditLedger, $this>
     */
    public function storeCreditLedgers(): HasMany
    {
        return $this->hasMany(\App\Modules\Pricing\Models\StoreCreditLedger::class);
    }

    public function isCompany(): bool
    {
        return strtoupper((string) $this->customer_type) === 'COMPANY';
    }

    public function isIndividual(): bool
    {
        return strtoupper((string) ($this->customer_type ?? 'INDIVIDUAL')) === 'INDIVIDUAL';
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->isCompany() && ! empty($this->company_name)) {
            return "{$this->name} ({$this->company_name})";
        }

        return (string) $this->name;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('merged_into_customer_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCompany(Builder $query): Builder
    {
        return $query->where('customer_type', 'COMPANY');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeIndividual(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('customer_type', 'INDIVIDUAL')
                ->orWhereNull('customer_type');
        });
    }
}
