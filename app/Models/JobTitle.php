<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\JobTitle as ModularJobTitle;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @deprecated Use canonical modular class instead. Maintained for backward compatibility.
 *
 * @see ModularJobTitle
 */
class JobTitle extends ModularJobTitle {}
