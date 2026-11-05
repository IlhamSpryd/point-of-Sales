<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string $cost_price
 * @property numeric-string $total_loss_amount
 * @property int $id
 * @property int $tenant_id
 * @property int $store_id
 * @property int $ingredient_id
 * @property int|null $logged_by_user_id
 */
class KitchenWasteLog extends Model
{
    use AssignsTenant, ScopedToTenant;

    protected $table = 'kitchen_waste_logs';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'ingredient_id',
        'quantity',
        'cost_price',
        'total_loss_amount',
        'reason',
        'waste_date',
        'logged_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'cost_price' => 'decimal:4',
            'total_loss_amount' => 'decimal:4',
            'waste_date' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by_user_id');
    }
}
