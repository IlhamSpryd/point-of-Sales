<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\OvertimeRecord as ModuleOvertimeRecord;

/**
 * Backward-compatibility wrapper for App\Modules\HR\Models\OvertimeRecord.
 */
class OvertimeRecord extends ModuleOvertimeRecord {}
