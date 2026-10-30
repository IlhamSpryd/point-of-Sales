<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\PayrollItem as ModulePayrollItem;

/**
 * Backward-compatibility wrapper for App\Modules\HR\Models\PayrollItem.
 */
class PayrollItem extends ModulePayrollItem {}
