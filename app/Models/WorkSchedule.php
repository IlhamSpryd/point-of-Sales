<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\WorkSchedule as ModularWorkSchedule;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see ModularWorkSchedule
 */
class WorkSchedule extends ModularWorkSchedule {}
