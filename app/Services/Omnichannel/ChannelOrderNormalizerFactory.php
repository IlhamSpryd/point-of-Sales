<?php

declare(strict_types=1);

namespace App\Services\Omnichannel;

use InvalidArgumentException;

final class ChannelOrderNormalizerFactory
{
    public static function make(string $provider): ChannelOrderNormalizer
    {
        return match (strtolower($provider)) {
            'grabfood' => new GrabFoodOrderNormalizer,
            'gofood' => new GoFoodOrderNormalizer,
            'generic', 'shopeefood', 'tiktokshop', 'custom', 'marketplace' => new GenericChannelOrderNormalizer,
            default => throw new InvalidArgumentException("Provider omnichannel tidak dikenal: {$provider}"),
        };
    }
}
