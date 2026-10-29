<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * total_amount YANG DILAPORKAN PROVIDER SENGAJA TIDAK
 * disertakan -- lihat catatan Zero-Trust di ProcessWebhookOrderJob: total
 * tagihan sah HANYA dihitung ulang dari harga Product internal. | 2026-09-23
 */
final class NormalizedChannelOrder
{
    /** @param NormalizedChannelOrderItem[] $items */
    public function __construct(
        public readonly string $externalOrderId,
        public readonly array $items,
        public readonly ?string $customerName = null,
        public readonly ?string $customerPhone = null,
        public readonly ?string $notes = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $channelCode = null,
        public readonly array $discounts = [],
        public readonly string $deliveryFee = '0.00',
        public readonly string $orderType = 'Takeaway',
        public readonly ?string $channelStatus = null,
    ) {}
}
