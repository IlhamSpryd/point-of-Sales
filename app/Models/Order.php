<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Backward compatibility wrapper for modular architecture.
 *
 * @see \App\Modules\Sales\Models\Order
 */
class Order extends \App\Modules\Sales\Models\Order {}
