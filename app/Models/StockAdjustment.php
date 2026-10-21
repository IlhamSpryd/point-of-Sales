<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Models\StockAdjustment as ModularStockAdjustment;

/**
 * Backward compatibility wrapper.
 *
 * @see ModularStockAdjustment
 */
class StockAdjustment extends ModularStockAdjustment {}
