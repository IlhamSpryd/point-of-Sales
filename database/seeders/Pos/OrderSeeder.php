<?php

namespace Database\Seeders\Pos;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OrderSeeder: The heaviest seeder — generates all transactional data together.
 *
 * Creates orders, order_items, order_item_modifiers, payments, stock_movements,
 * and ingredient_stock_movements in coordinated batches to maintain FK integrity.
 *
 * Target: ~50,000 orders across 365 days.
 */
class OrderSeeder extends Seeder
{
    public function run(): void
    {
        set_time_limit(0);
        ini_set('memory_limit', '2G');

        $now = Carbon::now();
        $startDate = $now->copy()->subDays(365);

        // Load reference data
        $products = DB::table('products')
            ->select('id', 'product_code', 'product_price', 'category_id')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        $productIds = $products->keys()->toArray();
        $productCount = count($productIds);

        // Beverage products (cat 1-5) IDs
        $beverageIds = $products->filter(fn ($p) => $p->category_id <= 5)->keys()->toArray();
        $foodIds = $products->filter(fn ($p) => $p->category_id >= 6 && $p->category_id <= 10)->keys()->toArray();

        // Load modifier groups per product
        $mgpLinks = DB::table('modifier_group_product')
            ->select('product_id', 'modifier_group_id')
            ->get()
            ->groupBy('product_id');

        // Load modifiers by group
        $modsByGroup = DB::table('modifiers')
            ->select('id', 'modifier_group_id', 'extra_price', 'is_default')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('modifier_group_id');

        // Load product_ingredients for ingredient stock movements
        $productIngredients = DB::table('product_ingredients')
            ->select('product_id', 'ingredient_id', 'quantity_required')
            ->get()
            ->groupBy('product_id');

        // Load shifts (closed ones ordered by opened_at)
        $shifts = DB::table('shifts')
            ->select('id', 'user_id', 'opened_at', 'closed_at', 'status')
            ->orderBy('opened_at')
            ->get();

        // Build shift lookup by date
        $shiftsByDate = [];
        foreach ($shifts as $s) {
            $openedLocal = Carbon::parse($s->opened_at)->setTimezone('Asia/Jakarta');
            $dateKey = $openedLocal->format('Y-m-d');
            $shiftsByDate[$dateKey][] = $s;
        }

        // Load discounts
        $discounts = DB::table('discounts')->where('is_active', 1)->get();

        // Active customer IDs (non-deleted)
        $customerIds = DB::table('customers')
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->pluck('id')
            ->toArray();

        // Table IDs
        $tableIds = DB::table('tables')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        // Kasir user IDs
        $kasirIds = [2, 9, 10, 11, 12, 13, 14, 15];
        // Barista IDs for processed_by
        $baristaIds = [16, 17, 18, 19, 20, 21];
        // Cook IDs
        $cookIds = [28, 29, 30, 31];
        // Manager/Supervisor for void
        $managerIds = [1, 3, 5, 6, 7, 8];

        // Indonesian holidays 2025-2026 for volume boost
        $holidays = [
            // Approximate dates — main point is boosted volume
            '2025-12-25', '2026-01-01', '2026-01-29', // Natal, Tahun Baru, Imlek
            '2026-03-14', '2026-03-28', '2026-03-29', '2026-03-30', '2026-03-31', // Ramadan & Lebaran area
            '2026-05-01', '2026-05-13', '2026-06-01', // May Day, Waisak, Pancasila
            '2026-08-17', '2026-09-17', // Kemerdekaan, Maulid
        ];
        $holidaySet = array_flip($holidays);

        // ---- Truncate all transactional tables ----
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('order_item_modifiers')->delete();
        DB::table('stock_movements')->delete();
        DB::table('order_items')->delete();
        DB::table('payments')->delete();
        DB::table('orders')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // ---- Generate day by day ----
        $orderBatch = [];
        $orderItemBatch = [];
        $orderItemModBatch = [];
        $paymentBatch = [];
        $stockMovBatch = [];

        $orderId = 0;
        $orderItemId = 0;
        $totalOrders = 0;
        $totalItems = 0;
        $totalPayments = 0;
        $totalStockMov = 0;

        $date = $startDate->copy();
        $today = $now->copy()->startOfDay();
        $totalDays = $startDate->diffInDays($today) + 1;

        $bar = $this->command->getOutput()->createProgressBar($totalDays);
        $bar->setFormat(' Orders [day]: %current%/%max% [%bar%] %percent:3s%%');

        // Growth factor: month 1 = 0.85, month 12 = 1.15
        $monthlyGrowth = [];
        for ($m = 0; $m < 12; $m++) {
            $monthlyGrowth[$m] = 0.85 + ($m * 0.30 / 11);
        }

        while ($date->lte($today)) {
            $dateStr = $date->format('Y-m-d');
            $isWeekend = $date->isWeekend();
            $isHoliday = isset($holidaySet[$dateStr]);
            $monthIndex = $startDate->diffInMonths($date);
            $monthIndex = min($monthIndex, 11);
            $growth = $monthlyGrowth[$monthIndex];

            // Determine order count for the day
            if ($isWeekend || $isHoliday) {
                $baseCount = rand(180, 280);
            } else {
                $baseCount = rand(80, 150);
            }
            $dayOrderCount = (int) round($baseCount * $growth);

            // Get shifts for this day
            $dayShifts = $shiftsByDate[$dateStr] ?? [];
            if (empty($dayShifts)) {
                $date->addDay();
                $bar->advance();

                continue;
            }

            // Generate order times distributed across the day
            $orderTimes = [];
            for ($o = 0; $o < $dayOrderCount; $o++) {
                // Weighted hour distribution: breakfast, lunch, dinner peaks
                $hourWeights = [
                    7 => 8, 8 => 12, 9 => 10, 10 => 6,
                    11 => 8, 12 => 15, 13 => 14, 14 => 8,
                    15 => 6, 16 => 5, 17 => 7,
                    18 => 12, 19 => 15, 20 => 14, 21 => 10, 22 => 5,
                ];
                $hour = $this->weightedRandom($hourWeights);
                $minute = rand(0, 59);
                $second = rand(0, 59);
                $orderTime = Carbon::parse("$dateStr $hour:$minute:$second", 'Asia/Jakarta');
                $orderTimes[] = $orderTime;
            }
            sort($orderTimes);

            $dailyOrderNum = 0;

            foreach ($orderTimes as $orderTime) {
                $orderId++;
                $dailyOrderNum++;
                $totalOrders++;

                // Find matching shift
                $shift = null;
                foreach ($dayShifts as $s) {
                    $sOpen = Carbon::parse($s->opened_at)->setTimezone('Asia/Jakarta');
                    $sClose = $s->closed_at ? Carbon::parse($s->closed_at)->setTimezone('Asia/Jakarta') : $sOpen->copy()->addHours(8);
                    if ($orderTime->between($sOpen->copy()->subMinutes(10), $sClose->copy()->addMinutes(10))) {
                        $shift = $s;
                        break;
                    }
                }
                if (! $shift) {
                    $shift = $dayShifts[array_rand($dayShifts)];
                }

                // Order type
                $typeRoll = rand(1, 100);
                if ($typeRoll <= 60) {
                    $orderType = 'dine_in';
                } elseif ($typeRoll <= 85) {
                    $orderType = 'takeaway';
                } elseif ($typeRoll <= 95) {
                    $orderType = 'delivery';
                } else {
                    $orderType = 'self_order';
                }

                // Status
                $statusRoll = rand(1, 100);
                if ($statusRoll <= 92) {
                    $orderStatus = 'paid';
                } elseif ($statusRoll <= 97) {
                    $orderStatus = 'pending';
                } elseif ($statusRoll <= 99) {
                    $orderStatus = 'cancelled';
                } else {
                    $orderStatus = 'void';
                }

                // Payment method
                $payRoll = rand(1, 100);
                if ($payRoll <= 55) {
                    $paymentMethod = 'cash';
                } elseif ($payRoll <= 80) {
                    $paymentMethod = 'qris';
                } elseif ($payRoll <= 95) {
                    $paymentMethod = 'ewallet';
                } else {
                    $paymentMethod = 'card';
                }

                // Customer (60% have a customer)
                $customerId = (rand(1, 100) <= 60 && ! empty($customerIds))
                    ? $customerIds[array_rand($customerIds)]
                    : null;

                // Table (dine_in only)
                $tableId = ($orderType === 'dine_in' && ! empty($tableIds))
                    ? $tableIds[array_rand($tableIds)]
                    : null;

                // Discount (15% of orders)
                $discountId = null;
                $discountAmount = 0;
                if (rand(1, 100) <= 15 && $discounts->count() > 0) {
                    $disc = $discounts->random();
                    $discountId = $disc->id;
                }

                // Generate items (1-6 items per order)
                $itemCount = $this->weightedRandom([1 => 15, 2 => 30, 3 => 25, 4 => 15, 5 => 10, 6 => 5]);
                $subtotal = 0;
                $orderItems = [];
                $usedProductIds = [];

                for ($it = 0; $it < $itemCount; $it++) {
                    $orderItemId++;
                    $totalItems++;

                    // Mix beverages and food
                    if ($it === 0 && rand(1, 100) <= 70) {
                        // First item is likely a beverage
                        $pid = $beverageIds[array_rand($beverageIds)];
                    } elseif ($it >= 1 && rand(1, 100) <= 50 && ! empty($foodIds)) {
                        $pid = $foodIds[array_rand($foodIds)];
                    } else {
                        $pid = $productIds[array_rand($productIds)];
                    }
                    // Avoid duplicate products in same order
                    $attempts = 0;
                    while (in_array($pid, $usedProductIds) && $attempts < 10) {
                        $pid = $productIds[array_rand($productIds)];
                        $attempts++;
                    }
                    $usedProductIds[] = $pid;

                    $product = $products[$pid];
                    $qty = $this->weightedRandom([1 => 60, 2 => 25, 3 => 10, 4 => 5]);
                    $itemPrice = $product->product_price;
                    $modifierTotal = 0;

                    // Determine modifiers for this item
                    $itemModifiers = [];
                    $groups = $mgpLinks->get($pid, collect());
                    foreach ($groups as $link) {
                        $gMods = $modsByGroup->get($link->modifier_group_id, collect());
                        if ($gMods->isEmpty()) {
                            continue;
                        }
                        // 60% chance to pick a non-default modifier if group is optional
                        if (rand(1, 100) <= 60) {
                            $mod = $gMods->random();
                        } else {
                            $mod = $gMods->firstWhere('is_default', 1) ?? $gMods->first();
                        }

                        $modPrice = (int) $mod->extra_price;
                        $modifierTotal += $modPrice;

                        $itemModifiers[] = [
                            'order_item_id' => $orderItemId,
                            'modifier_id' => $mod->id,
                            'price_at_time' => $modPrice,
                            'qty' => 1,
                            'created_at' => $orderTime->copy()->setTimezone('UTC'),
                            'updated_at' => $orderTime->copy()->setTimezone('UTC'),
                        ];
                    }

                    $linePrice = $itemPrice + $modifierTotal;
                    $lineSubtotal = $linePrice * $qty;
                    $subtotal += $lineSubtotal;

                    // Processed by
                    $processedBy = null;
                    if ($orderStatus === 'paid' || $orderStatus === 'pending') {
                        if ($product->category_id <= 5) {
                            $processedBy = $baristaIds[array_rand($baristaIds)];
                        } else {
                            $processedBy = $cookIds[array_rand($cookIds)];
                        }
                    }

                    $prepStatus = match ($orderStatus) {
                        'paid' => 'ready',
                        'pending' => ['pending', 'brewing', 'ready'][rand(0, 2)],
                        default => 'pending',
                    };

                    $orderItems[] = [
                        'id' => $orderItemId,
                        'order_id' => $orderId,
                        'product_id' => $pid,
                        'qty' => $qty,
                        'order_price' => $linePrice,
                        'order_subtotal' => $lineSubtotal,
                        'options' => null,
                        'notes' => null,
                        'preparation_status' => $prepStatus,
                        'processed_by' => $processedBy,
                        'created_at' => $orderTime->copy()->setTimezone('UTC'),
                        'updated_at' => $orderTime->copy()->setTimezone('UTC')->addMinutes(rand(5, 30)),
                        '_modifiers' => $itemModifiers,
                        '_product_id' => $pid,
                        '_qty' => $qty,
                    ];
                }

                // Calculate financials
                if ($discountId && $discountAmount === 0) {
                    $disc = $discounts->firstWhere('id', $discountId);
                    if ($disc && $subtotal >= $disc->min_purchase_amount) {
                        if ($disc->type === 'percentage') {
                            $discountAmount = (int) floor($subtotal * $disc->value / 100);
                            if ($disc->max_discount_amount) {
                                $discountAmount = min($discountAmount, $disc->max_discount_amount);
                            }
                        } else {
                            $discountAmount = (int) $disc->value;
                        }
                    } else {
                        $discountId = null;
                    }
                }

                $taxableAmount = $subtotal - $discountAmount;
                $taxAmount = (int) round($taxableAmount * 0.11);
                $serviceCharge = 0; // service_charge_rate is 0 per settings
                $orderAmount = $taxableAmount + $taxAmount + $serviceCharge;

                // Round to nearest 100
                $orderAmount = (int) round($orderAmount / 100) * 100;

                $cashReceived = null;
                $orderChange = 0;
                if ($paymentMethod === 'cash' && $orderStatus === 'paid') {
                    // Round up cash received
                    $cashReceived = (int) (ceil($orderAmount / 10000) * 10000);
                    if ($cashReceived < $orderAmount) {
                        $cashReceived = $orderAmount;
                    }
                    $orderChange = $cashReceived - $orderAmount;
                }

                $voidedBy = null;
                $voidReason = null;
                $voidedAt = null;
                if ($orderStatus === 'void') {
                    $voidedBy = $managerIds[array_rand($managerIds)];
                    $voidReason = ['Customer complaint', 'Wrong order', 'Kitchen error', 'Duplicate order', 'System error'][rand(0, 4)];
                    $voidedAt = $orderTime->copy()->addMinutes(rand(5, 60))->setTimezone('UTC');
                }

                $userId = $orderType === 'self_order' ? 4 : $shift->user_id;

                $orderBatch[] = [
                    'id' => $orderId,
                    'user_id' => $userId,
                    'shift_id' => $shift->id,
                    'discount_id' => $discountId,
                    'customer_id' => $customerId,
                    'table_id' => $tableId,
                    'order_type' => $orderType,
                    'order_code' => 'KSN-'.$date->format('Ymd').'-'.str_pad($dailyOrderNum, 4, '0', STR_PAD_LEFT),
                    'idempotency_key' => Str::uuid()->toString(),
                    'order_date' => $dateStr,
                    'subtotal_amount' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'tax_amount' => $taxAmount,
                    'service_charge_amount' => $serviceCharge,
                    'order_amount' => $orderAmount,
                    'order_change' => $orderChange,
                    'cash_received' => $cashReceived,
                    'order_status' => $orderStatus,
                    'voided_by' => $voidedBy,
                    'void_reason' => $voidReason,
                    'voided_at' => $voidedAt,
                    'payment_method' => $paymentMethod,
                    'snap_token' => ($paymentMethod === 'card' || $paymentMethod === 'ewallet' || $paymentMethod === 'qris') ? Str::random(36) : null,
                    'created_at' => $orderTime->copy()->setTimezone('UTC'),
                    'updated_at' => $orderTime->copy()->setTimezone('UTC')->addMinutes(rand(1, 30)),
                ];

                // Build order_items, modifiers
                foreach ($orderItems as $oi) {
                    $mods = $oi['_modifiers'];
                    $productId = $oi['_product_id'];
                    $itemQty = $oi['_qty'];
                    unset($oi['_modifiers'], $oi['_product_id'], $oi['_qty']);

                    $orderItemBatch[] = $oi;

                    foreach ($mods as $mod) {
                        $orderItemModBatch[] = $mod;
                    }

                    // Stock movements (for paid orders)
                    if ($orderStatus === 'paid') {
                        $totalStockMov++;
                        $stockMovBatch[] = [
                            'product_id' => $productId,
                            'order_id' => $orderId,
                            'order_item_id' => $oi['id'],
                            'type' => 'out',
                            'quantity' => -$itemQty,
                            'reason' => 'Penjualan order #'.$orderId,
                            'idempotency_key' => Str::uuid()->toString(),
                            'created_at' => $oi['created_at'],
                            'updated_at' => $oi['updated_at'],
                        ];
                    }
                }

                // Payment (for paid orders)
                if ($orderStatus === 'paid') {
                    $isSplit = rand(1, 100) <= 10; // 10% split payment
                    if ($isSplit && $orderAmount > 50000) {
                        $firstAmount = (int) round($orderAmount * rand(40, 60) / 100 / 100) * 100;
                        $secondAmount = $orderAmount - $firstAmount;
                        $methods = ['cash', 'qris', 'ewallet', 'card'];
                        $secondMethod = $methods[array_rand($methods)];

                        $paymentBatch[] = [
                            'order_id' => $orderId,
                            'payment_method' => $paymentMethod,
                            'amount' => $firstAmount,
                            'reference_number' => ($paymentMethod !== 'cash') ? 'REF-'.strtoupper(Str::random(10)) : null,
                            'status' => 'captured',
                            'captured_at' => $orderTime->copy()->setTimezone('UTC'),
                            'processed_by' => $shift->user_id,
                            'idempotency_key' => Str::uuid()->toString(),
                            'created_at' => $orderTime->copy()->setTimezone('UTC'),
                            'updated_at' => $orderTime->copy()->setTimezone('UTC'),
                        ];
                        $paymentBatch[] = [
                            'order_id' => $orderId,
                            'payment_method' => $secondMethod,
                            'amount' => $secondAmount,
                            'reference_number' => ($secondMethod !== 'cash') ? 'REF-'.strtoupper(Str::random(10)) : null,
                            'status' => 'captured',
                            'captured_at' => $orderTime->copy()->setTimezone('UTC'),
                            'processed_by' => $shift->user_id,
                            'idempotency_key' => Str::uuid()->toString(),
                            'created_at' => $orderTime->copy()->setTimezone('UTC'),
                            'updated_at' => $orderTime->copy()->setTimezone('UTC'),
                        ];
                        $totalPayments += 2;
                    } else {
                        $paymentBatch[] = [
                            'order_id' => $orderId,
                            'payment_method' => $paymentMethod,
                            'amount' => $orderAmount,
                            'reference_number' => ($paymentMethod !== 'cash') ? 'REF-'.strtoupper(Str::random(10)) : null,
                            'status' => 'captured',
                            'captured_at' => $orderTime->copy()->setTimezone('UTC'),
                            'processed_by' => $shift->user_id,
                            'idempotency_key' => Str::uuid()->toString(),
                            'created_at' => $orderTime->copy()->setTimezone('UTC'),
                            'updated_at' => $orderTime->copy()->setTimezone('UTC'),
                        ];
                        $totalPayments++;
                    }
                }

                // Flush batches periodically (every 500 orders)
                if (count($orderBatch) >= 500) {
                    $this->flushBatches($orderBatch, $orderItemBatch, $orderItemModBatch, $paymentBatch, $stockMovBatch);
                    $orderBatch = [];
                    $orderItemBatch = [];
                    $orderItemModBatch = [];
                    $paymentBatch = [];
                    $stockMovBatch = [];
                }
            }

            $date->addDay();
            $bar->advance();
        }

        // Flush remaining
        if (! empty($orderBatch)) {
            $this->flushBatches($orderBatch, $orderItemBatch, $orderItemModBatch, $paymentBatch, $stockMovBatch);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("  ✓ Orders: {$totalOrders}, Items: {$totalItems}, Payments: {$totalPayments}, Stock Movements: {$totalStockMov}");
    }

    private function flushBatches(array &$orders, array &$items, array &$mods, array &$payments, array &$stockMov): void
    {
        DB::transaction(function () use ($orders, $items, $mods, $payments, $stockMov) {
            foreach (array_chunk($orders, 500) as $chunk) {
                DB::table('orders')->insert($chunk);
            }
            foreach (array_chunk($items, 1000) as $chunk) {
                DB::table('order_items')->insert($chunk);
            }
            if (! empty($mods)) {
                foreach (array_chunk($mods, 1000) as $chunk) {
                    DB::table('order_item_modifiers')->insert($chunk);
                }
            }
            if (! empty($payments)) {
                foreach (array_chunk($payments, 1000) as $chunk) {
                    DB::table('payments')->insert($chunk);
                }
            }
            if (! empty($stockMov)) {
                foreach (array_chunk($stockMov, 1000) as $chunk) {
                    DB::table('stock_movements')->insert($chunk);
                }
            }
        });
    }

    private function weightedRandom(array $weights): int
    {
        $total = array_sum($weights);
        $rand = rand(1, $total);
        $cumulative = 0;
        foreach ($weights as $value => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                return $value;
            }
        }

        return array_key_first($weights);
    }
}
