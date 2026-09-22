<?php

declare(strict_types=1);

namespace App\Services\Omnichannel;

use App\DataTransferObjects\NormalizedChannelOrder;
use App\DataTransferObjects\NormalizedChannelOrderItem;

/**
 * [OMEGA-NODE5] PERLU VERIFIKASI DOCS: sama seperti adapter GrabFood --
 * nama field ASUMSI pola umum GoBiz API (order_number, order_items[].
 * item_id/qty, customer_detail.name/phone_number). WAJIB dicocokkan ke
 * dokumentasi resmi sebelum production. | 2026-09-23
 */
final class GoFoodOrderNormalizer implements ChannelOrderNormalizer
{
    public function normalize(array $payload): NormalizedChannelOrder
    {
        $items = collect($payload['order_items'] ?? $payload['items'] ?? [])
            ->map(fn (array $item) => new NormalizedChannelOrderItem(
                externalProductId: (string) ($item['item_id'] ?? $item['sku'] ?? ''),
                name: (string) ($item['name'] ?? 'Item GoFood'),
                quantity: max(1, (int) ($item['qty'] ?? $item['quantity'] ?? 1)),
            ))
            ->filter(fn (NormalizedChannelOrderItem $i) => $i->externalProductId !== '')
            ->values()
            ->all();

        return new NormalizedChannelOrder(
            externalOrderId: (string) ($payload['order_number'] ?? $payload['order_id'] ?? ''),
            items: $items,
            customerName: $payload['customer_detail']['name'] ?? null,
            customerPhone: $payload['customer_detail']['phone_number'] ?? null,
            notes: $payload['notes'] ?? null,
        );
    }
}
