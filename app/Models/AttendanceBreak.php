<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\AttendanceBreak as ModularAttendanceBreak;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see ModularAttendanceBreak
 */
class AttendanceBreak extends ModularAttendanceBreak {}
