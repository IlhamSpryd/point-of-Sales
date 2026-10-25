<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Core\Traits\AssignsTenant as CoreAssignsTenant;

/**
 * Backward compatibility wrapper.
 *
 * @see CoreAssignsTenant
 */
trait AssignsTenant
{
    use CoreAssignsTenant;
}
