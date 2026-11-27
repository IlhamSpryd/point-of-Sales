<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Core\Traits\AssignsTenant;
use App\Core\Traits\HasStoreRelation;
use App\Core\Traits\ScopedToTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $campaign_id
 * @property int $store_id
 */
class PromotionStoreMapping extends Model
{
    use AssignsTenant;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasStoreRelation;
    use ScopedToTenant;

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'store_id',
    ];

    /**
     * @return BelongsTo<PromotionCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PromotionCampaign::class, 'campaign_id');
    }
}
