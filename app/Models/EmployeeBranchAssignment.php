<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\EmployeeBranchAssignment as ModularEmployeeBranchAssignment;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see ModularEmployeeBranchAssignment
 */
class EmployeeBranchAssignment extends ModularEmployeeBranchAssignment {}
