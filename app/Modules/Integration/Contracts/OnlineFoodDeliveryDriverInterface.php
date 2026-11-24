<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

use App\DataTransferObjects\NormalizedChannelOrder;
use App\Modules\Integration\Models\OfdChannelConfig;

interface OnlineFoodDeliveryDriverInterface
{
    public function getProviderName(): string;

    public function verifyWebhookSignature(string $rawPayload, ?string $signature, string $secret): bool;

    public function normalizeWebhookOrder(array $payload, ?OfdChannelConfig $config = null): NormalizedChannelOrder;

    public function syncItemAvailability(\App\Modules\Organization\Models\Store $store, \App\Modules\Catalog\Models\Product $product, bool $available): bool;
}
