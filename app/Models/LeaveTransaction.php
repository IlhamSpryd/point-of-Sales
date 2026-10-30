<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\LeaveTransaction as ModuleLeaveTransaction;

/**
 * Backward-compatibility wrapper for App\Modules\HR\Models\LeaveTransaction.
 */
class LeaveTransaction extends ModuleLeaveTransaction {}
