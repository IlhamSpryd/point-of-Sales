<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Customer;
use App\Models\Tenant;
use App\Modules\Customers\Enums\StoreCreditStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property numeric-string $current_balance
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 */
class StoreCreditAccount extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'current_balance',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => StoreCreditStatusEnum::class,
            'current_balance' => 'decimal:2',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<StoreCreditLedger, $this>
     */
    public function ledgers(): HasMany
    {
        return $this->hasMany(StoreCreditLedger::class);
    }

    public function isActive(): bool
    {
        return $this->status === StoreCreditStatusEnum::ACTIVE;
    }
}
