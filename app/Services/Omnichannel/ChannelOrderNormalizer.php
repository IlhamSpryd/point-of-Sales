<?php

declare(strict_types=1);

namespace App\Services\Omnichannel;

use App\DataTransferObjects\NormalizedChannelOrder;

interface ChannelOrderNormalizer
{
    /** @param array<string, mixed> $payload Payload webhook mentah dari provider. */
    public function normalize(array $payload): NormalizedChannelOrder;
}
