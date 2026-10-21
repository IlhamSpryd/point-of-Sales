<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Models\GoodsReceipt as ModularGoodsReceipt;

/**
 * Backward compatibility wrapper.
 *
 * @see ModularGoodsReceipt
 */
class GoodsReceipt extends ModularGoodsReceipt {}
