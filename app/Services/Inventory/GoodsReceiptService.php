<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Modules\Inventory\Services\GoodsReceiptService as ModularGoodsReceiptService;

/**
 * Backward compatibility wrapper.
 *
 * @see ModularGoodsReceiptService
 */
class GoodsReceiptService extends ModularGoodsReceiptService {}
