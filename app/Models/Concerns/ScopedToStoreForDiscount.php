<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Core\Traits\ScopedToStoreForDiscount as CoreScopedToStoreForDiscount;

/**
 * Backward compatibility wrapper.
 *
 * @see CoreScopedToStoreForDiscount
 */
trait ScopedToStoreForDiscount
{
    use CoreScopedToStoreForDiscount;
}
