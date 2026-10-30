<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionReward extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    public const REWARD_PERCENTAGE_DISCOUNT = 'PERCENTAGE_DISCOUNT';
    public const REWARD_FIXED_AMOUNT_DISCOUNT = 'FIXED_AMOUNT_DISCOUNT';
    public const REWARD_FREE_PRODUCT = 'FREE_PRODUCT';
    public const REWARD_SPECIAL_PRICE_PRODUCT = 'SPECIAL_PRICE_PRODUCT';

    protected $fillable = [
        'tenant_id',
        'rule_id',
        'reward_type',
        'discount_percentage',
        'discount_fixed_amount',
        'max_discount_amount',
        'reward_product_id',
        'reward_product_discount_percent',
    ];

    protected function casts(): array
    {
        return [
            'discount_percentage' => 'decimal:2',
            'discount_fixed_amount' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'reward_product_id' => 'integer',
            'reward_product_discount_percent' => 'decimal:2',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PromotionRule::class, 'rule_id');
    }

    public function rewardProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'reward_product_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
