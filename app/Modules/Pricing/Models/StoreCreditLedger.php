<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\HasStoreRelation;
use App\Core\Traits\ScopedToTenant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Organization\Models\User;
use App\Modules\Pricing\Enums\StoreCreditLedgerTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $amount
 * @property numeric-string $balance_after
 * @property int $id
 * @property int $tenant_id
 * @property int $store_credit_account_id
 * @property int $customer_id
 * @property int|null $store_id
 * @property int|null $reference_id
 * @property int|null $actor_user_id
 */
class StoreCreditLedger extends Model
{
    use AssignsTenant;
    use HasStoreRelation;
    use ScopedToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_credit_account_id',
        'customer_id',
        'store_id',
        'entry_type',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'expires_at',
        'idempotency_key',
        'actor_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => StoreCreditLedgerTypeEnum::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StoreCreditAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(StoreCreditAccount::class, 'store_credit_account_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
