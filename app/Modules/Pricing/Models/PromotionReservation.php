<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\Customers\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $reserved_amount
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int|null $campaign_id
 * @property int|null $voucher_id
 * @property int|null $customer_id
 * @property int|null $order_id
 */
class PromotionReservation extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToTenant;

    public const STATUS_RESERVED = 'RESERVED';

    public const STATUS_COMMITTED = 'COMMITTED';

    public const STATUS_RELEASED = 'RELEASED';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'campaign_id',
        'voucher_id',
        'customer_id',
        'cart_uuid',
        'reserved_amount',
        'status',
        'expires_at',
        'order_id',
    ];

    protected function casts(): array
    {
        return [
            'reserved_amount' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PromotionCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PromotionCampaign::class, 'campaign_id');
    }

    /**
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
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
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
