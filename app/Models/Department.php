<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\Department as ModularDepartment;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @deprecated Use canonical modular class instead. Maintained for backward compatibility.
 *
 * @see ModularDepartment
 */
class Department extends ModularDepartment {}
