<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AssignsTenant;
use Illuminate\Database\Eloquent\Model;

class LoyaltyTier extends Model
{
    use AssignsTenant;

    protected $guarded = ['id'];
}
