<?php

declare(strict_types=1);

namespace App\Services\Omnichannel;

use InvalidArgumentException;

final class ChannelOrderNormalizerFactory
{
    public static function make(string $provider): ChannelOrderNormalizer
    {
        return match ($provider) {
            'grabfood' => new GrabFoodOrderNormalizer,
            'gofood' => new GoFoodOrderNormalizer,
            default => throw new InvalidArgumentException("Provider omnichannel tidak dikenal: {$provider}"),
        };
    }
}
