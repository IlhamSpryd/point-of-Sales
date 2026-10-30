<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\PayrollPeriod as ModulePayrollPeriod;

/**
 * Backward-compatibility wrapper for App\Modules\HR\Models\PayrollPeriod.
 */
class PayrollPeriod extends ModulePayrollPeriod {}
