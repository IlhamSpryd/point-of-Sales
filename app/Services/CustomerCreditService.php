<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Customers\Services\CustomerCreditService as ModularCustomerCreditService;

/**
 * Backward compatibility wrapper for CustomerCreditService.
 */
class CustomerCreditService extends ModularCustomerCreditService {}
