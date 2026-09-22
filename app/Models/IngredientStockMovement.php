<?php
declare(strict_types=1);

namespace App\Models;

use App\Enums\IngredientStockMovementTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientStockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'type' => IngredientStockMovementTypeEnum::class,
        'quantity' => 'decimal:4',
        'balance_after' => 'decimal:4',
    ];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function update(array $attributes = [], array $options = [])
    {
        throw new \LogicException("Immutable Ledger: Modifikasi data dilarang oleh arsitektur Tier-1.");
    }

    public function delete()
    {
        throw new \LogicException("Immutable Ledger: Modifikasi data dilarang oleh arsitektur Tier-1.");
    }
}
