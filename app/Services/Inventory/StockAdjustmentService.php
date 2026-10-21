<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Modules\Inventory\Services\StockAdjustmentService as ModularStockAdjustmentService;

/**
 * Backward compatibility wrapper.
 *
 * @see ModularStockAdjustmentService
 */
class StockAdjustmentService extends ModularStockAdjustmentService {}
