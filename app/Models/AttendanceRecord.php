<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\AttendanceRecord as ModularAttendanceRecord;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see ModularAttendanceRecord
 */
class AttendanceRecord extends ModularAttendanceRecord {}
