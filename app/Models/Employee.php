<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\Employee as ModularEmployee;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see ModularEmployee
 */
class Employee extends ModularEmployee {}
