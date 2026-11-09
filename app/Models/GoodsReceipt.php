<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Models\GoodsReceipt as ModularGoodsReceipt;

/**
 * Backward compatibility wrapper.
 *
 * @deprecated Use canonical modular class instead. Maintained for backward compatibility.
 *
 * @see ModularGoodsReceipt
 */
class GoodsReceipt extends ModularGoodsReceipt {}
