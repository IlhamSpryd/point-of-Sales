<?php

use App\Livewire\Kds\Board;

$component = new Board;
$items = $component->pendingItems();
$orderItem = $items->firstWhere('order.order_code', 'POS-O1F174-976');
echo 'KDS_VISIBLE='.($orderItem ? 'YES' : 'NO')."\n";
echo 'PRODUCT_NAME='.($orderItem ? $orderItem->product->name : 'N/A')."\n";
