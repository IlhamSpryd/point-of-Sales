<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Core\Traits\AssignsStore as CoreAssignsStore;

/**
 * Backward compatibility wrapper.
 *
 * @see CoreAssignsStore
 */
trait AssignsStore
{
    use CoreAssignsStore;
}
