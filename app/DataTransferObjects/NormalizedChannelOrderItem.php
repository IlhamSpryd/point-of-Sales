<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

/**
 * [OMEGA-NODE5] Baris item yang SUDAH dinormalisasi dari payload mentah
 * provider, sebelum dipetakan ke Product internal. | 2026-09-23
 */
final class NormalizedChannelOrderItem
{
    public function __construct(
        public readonly string $externalProductId,
        public readonly string $name,
        public readonly int $quantity,
    ) {}
}
