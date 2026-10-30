<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Tenant;
use App\Modules\Customers\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionReservation extends Model
{
    use AssignsTenant;
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

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PromotionCampaign::class, 'campaign_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
