<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
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

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'type',
        'store_id',
        'customer_id',
        'loyalty_tier_id',
        'channel',
        'currency',
        'priority',
        'valid_from',
        'valid_until',
        'is_active',
        'description',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'customer_id' => 'integer',
        'loyalty_tier_id' => 'integer',
        'priority' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
    ];

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
