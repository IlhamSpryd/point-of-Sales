<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\CustomerGroup;
use App\Models\CustomerLoyaltyAccount;
use App\Models\CustomerMergeLog;
use App\Models\LoyaltyLedger;
use App\Models\Order;
use App\Models\PriceList;
use App\Models\Store;
use App\Models\User;
use App\Modules\Sales\Models\ArLedgerEntry;
use App\Modules\Sales\Models\ArPaymentAllocation;
use App\Modules\Sales\Models\CustomerInvoice;
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
 */
class Customer extends Model
{
    use AssignsTenant;
    use ScopedToTenant;
    use SoftDeletes;

    protected $guarded = ['id'];

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
     * @return HasOne<CustomerLoyaltyAccount, $this>
     */
    public function loyaltyAccount(): HasOne
    {
        return $this->hasOne(CustomerLoyaltyAccount::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<LoyaltyLedger, $this>
     */
    public function loyaltyLedgers(): HasMany
    {
        return $this->hasMany(LoyaltyLedger::class);
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
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
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
     * @return HasMany<CustomerInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class);
    }

    /**
     * @return HasMany<ArLedgerEntry, $this>
     */
    public function arLedgerEntries(): HasMany
    {
        return $this->hasMany(ArLedgerEntry::class);
    }

    /**
     * @return HasMany<ArPaymentAllocation, $this>
     */
    public function arPaymentAllocations(): HasMany
    {
        return $this->hasMany(ArPaymentAllocation::class);
    }

    /**
     * @return HasMany<Voucher, $this>
     */
    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * @return HasMany<GiftCard, $this>
     */
    public function giftCards(): HasMany
    {
        return $this->hasMany(GiftCard::class);
    }

    /**
     * @return HasOne<StoreCreditAccount, $this>
     */
    public function storeCreditAccount(): HasOne
    {
        return $this->hasOne(StoreCreditAccount::class);
    }

    /**
     * @return HasMany<StoreCreditLedger, $this>
     */
    public function storeCreditLedgers(): HasMany
    {
        return $this->hasMany(StoreCreditLedger::class);
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
