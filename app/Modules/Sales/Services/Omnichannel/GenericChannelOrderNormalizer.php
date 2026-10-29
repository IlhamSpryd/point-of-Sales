<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services\Omnichannel;

use App\DataTransferObjects\NormalizedChannelOrder;
use App\DataTransferObjects\NormalizedChannelOrderItem;

final class GenericChannelOrderNormalizer implements ChannelOrderNormalizer
{
    public function normalize(array $payload): NormalizedChannelOrder
    {
        $items = collect($payload['items'] ?? $payload['order_items'] ?? $payload['lines'] ?? [])
            ->map(fn (array $item) => new NormalizedChannelOrderItem(
                externalProductId: (string) ($item['external_product_id'] ?? $item['item_id'] ?? $item['sku'] ?? $item['product_id'] ?? ''),
                name: (string) ($item['name'] ?? $item['item_name'] ?? 'Item Kanal'),
                quantity: max(1, (int) ($item['quantity'] ?? $item['qty'] ?? 1)),
                unitPrice: isset($item['unit_price']) ? (string) $item['unit_price'] : (isset($item['price']) ? (string) $item['price'] : null),
                discountAmount: (string) ($item['discount_amount'] ?? '0.00'),
                notes: $item['notes'] ?? null
            ))
            ->filter(fn (NormalizedChannelOrderItem $i) => $i->externalProductId !== '')
            ->values()
            ->all();

        return new NormalizedChannelOrder(
            externalOrderId: (string) ($payload['order_id'] ?? $payload['order_number'] ?? $payload['id'] ?? $payload['external_order_id'] ?? ''),
            items: $items,
            customerName: $payload['customer_name'] ?? $payload['customer']['name'] ?? $payload['customer_detail']['name'] ?? null,
            customerPhone: $payload['customer_phone'] ?? $payload['customer']['phone'] ?? $payload['customer_detail']['phone'] ?? null,
            notes: $payload['notes'] ?? $payload['remark'] ?? null,
            customerEmail: $payload['customer_email'] ?? $payload['customer']['email'] ?? null,
            channelCode: (string) ($payload['channel'] ?? $payload['channel_code'] ?? 'generic'),
            discounts: (array) ($payload['discounts'] ?? []),
            deliveryFee: (string) ($payload['delivery_fee'] ?? '0.00'),
            orderType: (string) ($payload['order_type'] ?? 'Takeaway'),
            channelStatus: $payload['status'] ?? null
        );
    }
}
