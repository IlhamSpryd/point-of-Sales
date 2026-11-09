<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Models\StockAdjustmentItem as ModularStockAdjustmentItem;

/**
 * Backward compatibility wrapper.
 *
 * @deprecated Use canonical modular class instead. Maintained for backward compatibility.
 *
 * @see ModularStockAdjustmentItem
 */
class StockAdjustmentItem extends ModularStockAdjustmentItem {}
