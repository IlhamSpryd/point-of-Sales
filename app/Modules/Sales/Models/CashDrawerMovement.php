<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Enums\CashDrawerMovementCategoryEnum;
use App\Enums\CashDrawerMovementTypeEnum;
use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Device;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shift_id
 * @property int $amount
 * @property int $created_by
 * @property int|null $approved_by
 * @property int $tenant_id
 * @property int $store_id
 * @property int|null $device_id
 */
class CashDrawerMovement extends Model
{
    use AssignsStore, AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'type' => CashDrawerMovementTypeEnum::class,
        'category' => CashDrawerMovementCategoryEnum::class,
        'amount' => 'integer',
    ];

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new \LogicException('Immutable Ledger: Modifikasi data dilarang oleh arsitektur Tier-1.');
    }

    public function delete()
    {
        throw new \LogicException('Immutable Ledger: Modifikasi data dilarang oleh arsitektur Tier-1.');
    }
}
