<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Customers\Models\CustomerCreditLog as ModularCustomerCreditLog;

/**
 * Backward-compatible wrapper for CustomerCreditLog.
 */
class CustomerCreditLog extends ModularCustomerCreditLog {}
