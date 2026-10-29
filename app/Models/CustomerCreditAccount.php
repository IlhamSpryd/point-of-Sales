<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Customers\Models\CustomerCreditAccount as ModularCustomerCreditAccount;

/**
 * Backward-compatible wrapper for CustomerCreditAccount.
 */
class CustomerCreditAccount extends ModularCustomerCreditAccount {}
