<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Customers\Enums\VoucherDiscountTypeEnum;
use App\Modules\Customers\Enums\VoucherStatusEnum;
use App\Modules\Customers\Models\Voucher as BaseVoucher;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property VoucherStatusEnum|string $status
 * @property VoucherDiscountTypeEnum|string $discount_type
 * @property string|float $discount_value
 * @property string|float|null $max_discount_amount
 * @property string|float|null $min_order_amount
 * @property int $max_uses
 * @property int $used_count
 */
class Voucher extends BaseVoucher {}
