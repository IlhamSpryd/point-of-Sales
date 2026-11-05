<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property numeric-string $min_order_amount
 * @property int $id
 * @property int $tenant_id
 * @property int $campaign_id
 * @property int $min_order_qty
 * @property array<int|string>|null $trigger_product_ids
 * @property array<int|string>|null $trigger_category_ids
 */
class PromotionRule extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use ScopedToTenant;

    public const RULE_CART_TOTAL = 'CART_TOTAL';

    public const RULE_PRODUCT_MATCH = 'PRODUCT_MATCH';

    public const RULE_CATEGORY_MATCH = 'CATEGORY_MATCH';

    public const RULE_BXGY_TRIGGER = 'BXGY_TRIGGER';

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'rule_type',
        'trigger_product_ids',
        'trigger_category_ids',
        'min_order_amount',
        'min_order_qty',
    ];

    protected function casts(): array
    {
        return [
            'trigger_product_ids' => 'array',
            'trigger_category_ids' => 'array',
            'min_order_amount' => 'decimal:2',
            'min_order_qty' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PromotionCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PromotionCampaign::class, 'campaign_id');
    }

    /**
     * @return HasMany<PromotionReward, $this>
     */
    public function rewards(): HasMany
    {
        return $this->hasMany(PromotionReward::class, 'rule_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
