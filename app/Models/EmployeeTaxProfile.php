<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\EmployeeTaxProfile as ModularEmployeeTaxProfile;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see ModularEmployeeTaxProfile
 */
class EmployeeTaxProfile extends ModularEmployeeTaxProfile {}
