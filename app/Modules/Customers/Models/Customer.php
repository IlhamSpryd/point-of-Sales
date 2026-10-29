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

    public function loyaltyAccount(): HasOne
    {
        return $this->hasOne(CustomerLoyaltyAccount::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function loyaltyLedgers(): HasMany
    {
        return $this->hasMany(LoyaltyLedger::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function defaultBillingAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('is_default_billing', true);
    }

    public function defaultShippingAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('is_default_shipping', true);
    }

    public function defaultStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'default_store_id');
    }

    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_user_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function mergedIntoCustomer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_customer_id');
    }

    public function mergeLogsAsSource(): HasMany
    {
        return $this->hasMany(CustomerMergeLog::class, 'source_customer_id');
    }

    public function mergeLogsAsTarget(): HasMany
    {
        return $this->hasMany(CustomerMergeLog::class, 'target_customer_id');
    }

    public function creditAccount(): HasOne
    {
        return $this->hasOne(CustomerCreditAccount::class);
    }

    public function creditLogs(): HasMany
    {
        return $this->hasMany(CustomerCreditLog::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class);
    }

    public function arLedgerEntries(): HasMany
    {
        return $this->hasMany(ArLedgerEntry::class);
    }

    public function arPaymentAllocations(): HasMany
    {
        return $this->hasMany(ArPaymentAllocation::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function giftCards(): HasMany
    {
        return $this->hasMany(GiftCard::class);
    }

    public function storeCreditAccount(): HasOne
    {
        return $this->hasOne(StoreCreditAccount::class);
    }

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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('merged_into_customer_id');
    }

    public function scopeCompany(Builder $query): Builder
    {
        return $query->where('customer_type', 'COMPANY');
    }

    public function scopeIndividual(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('customer_type', 'INDIVIDUAL')
                ->orWhereNull('customer_type');
        });
    }
}
