<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\LeaveRequest as ModuleLeaveRequest;

/**
 * Backward-compatibility wrapper for App\Modules\HR\Models\LeaveRequest.
 */
class LeaveRequest extends ModuleLeaveRequest {}
