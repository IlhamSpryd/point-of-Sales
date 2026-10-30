<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\JobTitle as ModularJobTitle;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see ModularJobTitle
 */
class JobTitle extends ModularJobTitle {}
