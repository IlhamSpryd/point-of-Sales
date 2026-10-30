<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\ShiftTemplate as ModularShiftTemplate;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see ModularShiftTemplate
 */
class ShiftTemplate extends ModularShiftTemplate {}
