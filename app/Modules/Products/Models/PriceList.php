<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\LoyaltyTier;
use App\Models\PriceListItem;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Model PriceList mewakili skema daftar harga khusus/bertingkat (ADR-02).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $code
 * @property string $type (standard, branch, wholesale, customer_tier, customer_specific, channel)
 * @property int|null $store_id
 * @property int|null $customer_id
 * @property int|null $loyalty_tier_id
 * @property string|null $channel
 * @property string $currency
 * @property int $priority
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property bool $is_active
 * @property string|null $description
 */
class PriceList extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;
    use SoftDeletes;

    protected $table = 'price_lists';

    public const TARGET_GENERAL = 'GENERAL';

    public const TARGET_STORE = 'STORE';

    public const TARGET_CUSTOMER_GROUP = 'CUSTOMER_GROUP';

    public const TARGET_CUSTOMER_CONTRACT = 'CUSTOMER_CONTRACT';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'type',
        'target_type',
        'store_id',
        'customer_id',
        'customer_group_id',
        'loyalty_tier_id',
        'channel',
        'currency',
        'priority',
        'approved_by_user_id',
        'approved_at',
        'valid_from',
        'valid_until',
        'is_active',
        'allow_promo_stacking',
        'description',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'customer_id' => 'integer',
        'customer_group_id' => 'integer',
        'loyalty_tier_id' => 'integer',
        'priority' => 'integer',
        'approved_by_user_id' => 'integer',
        'approved_at' => 'datetime',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
        'allow_promo_stacking' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function loyaltyTier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class, 'price_list_id');
    }

    /**
     * Scope untuk mengambil price list yang aktif dan dalam masa berlaku saat ini.
     */
    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
            });
    }
}
