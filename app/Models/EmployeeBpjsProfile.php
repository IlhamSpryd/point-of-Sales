<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\EmployeeBpjsProfile as ModularEmployeeBpjsProfile;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see ModularEmployeeBpjsProfile
 */
class EmployeeBpjsProfile extends ModularEmployeeBpjsProfile {}
