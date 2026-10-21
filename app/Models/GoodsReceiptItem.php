<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Models\GoodsReceiptItem as ModularGoodsReceiptItem;

/**
 * Backward compatibility wrapper.
 *
 * @see ModularGoodsReceiptItem
 */
class GoodsReceiptItem extends ModularGoodsReceiptItem {}
