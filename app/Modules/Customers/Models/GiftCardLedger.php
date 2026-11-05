<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Enums\GiftCardLedgerTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $amount
 * @property numeric-string $balance_after
 * @property int $id
 * @property int $tenant_id
 * @property int $gift_card_id
 * @property int $store_id
 * @property int|null $reference_id
 * @property int|null $actor_user_id
 */
class GiftCardLedger extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'gift_card_id',
        'store_id',
        'entry_type',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'actor_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => GiftCardLedgerTypeEnum::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
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
     * @return BelongsTo<GiftCard, $this>
     */
    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
