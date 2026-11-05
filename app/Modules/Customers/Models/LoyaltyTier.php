<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property numeric-string $points_multiplier
 * @property int $id
 * @property int $min_points
 * @property int $tenant_id
 */
class LoyaltyTier extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $guarded = ['id'];
}
