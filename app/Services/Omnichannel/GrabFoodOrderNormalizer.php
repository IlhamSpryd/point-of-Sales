<?php

declare(strict_types=1);

namespace App\Services\Omnichannel;

use App\DataTransferObjects\NormalizedChannelOrder;
use App\DataTransferObjects\NormalizedChannelOrderItem;

/**
 * [OMEGA-NODE5] PERLU VERIFIKASI DOCS: nama field di bawah ASUMSI mengikuti
 * pola umum payload GrabFood Merchant API (order_id, items[].id/quantity,
 * customer.name/phone). Field salah nama = null diam-diam, BUKAN error
 * eksplisit -- WAJIB divalidasi dengan sample payload RESMI sebelum
 * production. | 2026-09-23
 */
final class GrabFoodOrderNormalizer implements ChannelOrderNormalizer
{
    public function normalize(array $payload): NormalizedChannelOrder
    {
        $items = collect($payload['items'] ?? [])
            ->map(fn (array $item) => new NormalizedChannelOrderItem(
                externalProductId: (string) ($item['id'] ?? $item['sku'] ?? ''),
                name: (string) ($item['name'] ?? 'Item GrabFood'),
                quantity: max(1, (int) ($item['quantity'] ?? 1)),
            ))
            ->filter(fn (NormalizedChannelOrderItem $i) => $i->externalProductId !== '')
            ->values()
            ->all();

        return new NormalizedChannelOrder(
            externalOrderId: (string) ($payload['order_id'] ?? $payload['orderID'] ?? ''),
            items: $items,
            customerName: $payload['customer']['name'] ?? null,
            customerPhone: $payload['customer']['phone'] ?? null,
            notes: $payload['notes'] ?? $payload['instructions'] ?? null,
        );
    }
}
