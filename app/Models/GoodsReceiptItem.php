<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Models\GoodsReceiptItem as ModularGoodsReceiptItem;

/**
 * Backward compatibility wrapper.
 *
 * @deprecated Use canonical modular class instead. Maintained for backward compatibility.
 *
 * @see ModularGoodsReceiptItem
 */
class GoodsReceiptItem extends ModularGoodsReceiptItem {}
