<?php

declare(strict_types=1);

namespace App\Modules\Settings\Models;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $plan_id
 */
class PlanEntitlement extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'feature_code',
        'feature_type',
        'value',
    ];

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
