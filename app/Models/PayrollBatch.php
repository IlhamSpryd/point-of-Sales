<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\PayrollBatch as ModulePayrollBatch;

/**
 * Backward-compatibility wrapper for App\Modules\HR\Models\PayrollBatch.
 */
class PayrollBatch extends ModulePayrollBatch {}
