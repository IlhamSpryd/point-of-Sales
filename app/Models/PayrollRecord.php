<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\PayrollRecord as ModulePayrollRecord;

/**
 * Backward-compatibility wrapper for App\Modules\HR\Models\PayrollRecord.
 */
class PayrollRecord extends ModulePayrollRecord {}
