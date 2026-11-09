<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Models\StockAdjustment as ModularStockAdjustment;

/**
 * Backward compatibility wrapper.
 *
 * @deprecated Use canonical modular class instead. Maintained for backward compatibility.
 *
 * @see ModularStockAdjustment
 */
class StockAdjustment extends ModularStockAdjustment {}
