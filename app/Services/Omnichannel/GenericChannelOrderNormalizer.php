<?php

declare(strict_types=1);

namespace App\Services\Omnichannel;

use App\DataTransferObjects\NormalizedChannelOrder;
use App\Modules\Sales\Services\Omnichannel\GenericChannelOrderNormalizer as ModuleGenericChannelOrderNormalizer;

final class GenericChannelOrderNormalizer implements ChannelOrderNormalizer
{
    protected ModuleGenericChannelOrderNormalizer $moduleNormalizer;

    public function __construct()
    {
        $this->moduleNormalizer = new ModuleGenericChannelOrderNormalizer;
    }

    public function normalize(array $payload): NormalizedChannelOrder
    {
        return $this->moduleNormalizer->normalize($payload);
    }
}
