<?php

declare(strict_types=1);

namespace App\Modules\Finance\DTOs;

class TaxInvoiceItemDto
{
    public function __construct(
        public string $code,
        public string $name,
        public string $unitPrice,
        public string $quantity,
        public string $totalPrice,
        public string $discountAmount = '0.00',
        public string $dpp = '0.00',
        public string $ppn = '0.00',
        public string $ppnbmTariff = '0.00',
        public string $ppnbm = '0.00'
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            code: (string) ($data['code'] ?? 'BRG-01'),
            name: (string) ($data['name'] ?? 'Item'),
            unitPrice: (string) ($data['unit_price'] ?? '0.00'),
            quantity: (string) ($data['quantity'] ?? '1'),
            totalPrice: (string) ($data['total_price'] ?? '0.00'),
            discountAmount: (string) ($data['discount_amount'] ?? '0.00'),
            dpp: (string) ($data['dpp'] ?? '0.00'),
            ppn: (string) ($data['ppn'] ?? '0.00'),
            ppnbmTariff: (string) ($data['ppnbm_tariff'] ?? '0.00'),
            ppnbm: (string) ($data['ppnbm'] ?? '0.00')
        );
    }
}

