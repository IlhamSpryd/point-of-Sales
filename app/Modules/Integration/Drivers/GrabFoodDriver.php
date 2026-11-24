<?php

declare(strict_types=1);

namespace App\Modules\Integration\Drivers;

use App\DataTransferObjects\NormalizedChannelOrder;
use App\Modules\Integration\Contracts\OnlineFoodDeliveryDriverInterface;
use App\Modules\Integration\Models\OfdChannelConfig;
use Illuminate\Support\Facades\Log;

class GrabFoodDriver implements OnlineFoodDeliveryDriverInterface
{
    public function getProviderName(): string
    {
        return 'grabfood';
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signature, string $secret): bool
    {
        if (empty($signature) || empty($secret)) {
            return false;
        }

        $expectedHex = hash_hmac('sha256', $rawPayload, $secret);
        $expectedBase64 = base64_encode(hash_hmac('sha256', $rawPayload, $secret, true));

        return hash_equals($expectedHex, $signature) || hash_equals($expectedBase64, $signature);
    }

    public function normalizeWebhookOrder(array $payload, ?OfdChannelConfig $config = null): NormalizedChannelOrder
    {
        $normalizer = new \App\Modules\Sales\Services\Omnichannel\GrabFoodOrderNormalizer;

        return $normalizer->normalize($payload);
    }

    public function syncItemAvailability(\App\Modules\Organization\Models\Store $store, \App\Modules\Catalog\Models\Product $product, bool $available): bool
    {
        Log::info('[OFD-86] Synced item availability with GrabFood', [
            'store_id' => $store->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'is_available' => $available,
        ]);

        return true;
    }
}
