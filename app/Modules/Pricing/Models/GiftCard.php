<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\HasStoreRelation;
use App\Core\Traits\ScopedToTenant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Pricing\Enums\GiftCardStatusEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property numeric-string $initial_balance
 * @property numeric-string $current_balance
 * @property GiftCardStatusEnum $status
 * @property int $id
 * @property int $tenant_id
 * @property int|null $issued_store_id
 * @property int|null $customer_id
 * @property int|null $issued_by_order_id
 */
class GiftCard extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasStoreRelation;
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'issued_store_id',
        'card_number',
        'pin_hash',
        'customer_id',
        'initial_balance',
        'current_balance',
        'status',
        'expires_at',
        'issued_by_order_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => GiftCardStatusEnum::class,
            'initial_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<GiftCardLedger, $this>
     */
    public function ledgers(): HasMany
    {
        return $this->hasMany(GiftCardLedger::class);
    }

    public function isValidForRedemption(): bool
    {
        if ($this->status !== GiftCardStatusEnum::ACTIVE) {
            return false;
        }

        if (bccomp((string) $this->current_balance, '0.00', 2) <= 0) {
            return false;
        }

        if ($this->expires_at && Carbon::now()->gt($this->expires_at)) {
            return false;
        }

        return true;
    }
}
