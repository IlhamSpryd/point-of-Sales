<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Core\Traits\ScopedToStore as CoreScopedToStore;

/**
 * Backward compatibility wrapper.
 *
 * @see CoreScopedToStore
 */
trait ScopedToStore
{
    use CoreScopedToStore;
}
