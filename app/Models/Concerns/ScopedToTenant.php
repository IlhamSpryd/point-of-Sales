<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Core\Traits\ScopedToTenant as CoreScopedToTenant;

/**
 * Backward compatibility wrapper.
 *
 * @see CoreScopedToTenant
 */
trait ScopedToTenant
{
    use CoreScopedToTenant;
}
