<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Model;

class LoyaltyTier extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $guarded = ['id'];
}
