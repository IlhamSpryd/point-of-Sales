<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Backward compatibility wrapper for StockOpnameDriftException.
 *
 * @see \App\Modules\Inventory\Exceptions\StockOpnameDriftException
 */
class StockOpnameDriftException extends \App\Modules\Inventory\Exceptions\StockOpnameDriftException {}
