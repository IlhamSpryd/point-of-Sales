<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\EmployeeEmploymentHistory as ModularEmployeeEmploymentHistory;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see ModularEmployeeEmploymentHistory
 */
class EmployeeEmploymentHistory extends ModularEmployeeEmploymentHistory {}
