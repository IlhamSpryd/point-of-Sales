<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\HR\Models\EmployeeBankAccount as ModularEmployeeBankAccount;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see ModularEmployeeBankAccount
 */
class EmployeeBankAccount extends ModularEmployeeBankAccount {}
