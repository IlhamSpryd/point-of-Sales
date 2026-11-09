<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @deprecated Use canonical modular class instead. Maintained for backward compatibility.
 *
 * @see \App\Modules\Customers\Models\Customer
 */
class Customer extends \App\Modules\Customers\Models\Customer {}
