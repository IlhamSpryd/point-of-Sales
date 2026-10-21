<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Enums\IngredientStockMovementTypeEnum;
use App\Models\Concerns\AssignsStore;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToStore;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Ingredient;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $ingredient_id
 * @property int|null $order_id
 * @property IngredientStockMovementTypeEnum $type
 * @property string $quantity
 * @property string|null $unit_cost
 * @property string $balance_after
 * @property string|null $reason
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property-read Ingredient|null $ingredient
 * @property-read Store|null $store
 */
class IngredientStockMovement extends Model
{
    use AssignsStore, AssignsTenant;
    use ScopedToStore;
    use ScopedToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'type' => IngredientStockMovementTypeEnum::class,
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'balance_after' => 'decimal:4',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
