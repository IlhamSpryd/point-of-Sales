<?php

declare(strict_types=1);

namespace App\Core\Traits;

use App\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait BelongsToCompany
 * Canonical Tier 2 organizational scoping for multi-company legal entity isolation.
 */
trait BelongsToCompany
{
    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}

